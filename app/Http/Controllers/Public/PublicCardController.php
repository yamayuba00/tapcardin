<?php

namespace App\Http\Controllers\Public;

use App\Actions\Cards\CardView;
use App\Actions\Cards\ShareQr;
use App\Actions\Events\EventForCard;
use App\Actions\Leads\CreateConnection;
use App\Enums\LeadSource;
use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Http\Controllers\SpaController;
use App\Models\Card;
use App\Models\ProductEvent;
use App\Models\ProfileField;
use App\Models\Tap;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Contacts\ContactTypes;
use App\Support\Contacts\ContactValue;
use App\Support\Crockford;
use App\Support\Hasher;
use App\Support\Phone;
use App\Support\Qr\SvgRenderer;
use App\Support\VCard;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PublicCardController extends Controller
{
    /**
     * GET /c/{token}/vcard — tombol "Simpan ke Kontak HP". Tidak terasa sebagai unduhan file:
     *  - Chrome / Samsung Internet di Android → intent "Tambah kontak" bawaan (form kontak HP langsung terbuka);
     *  - iPhone & lainnya → vCard 3.0 inline (TRD §10.1), iOS langsung menampilkan layar "Kontak Baru".
     * `?file=1` = vCard tanpa mencatat ulang (cadangan bila intent tidak bisa dibuka).
     */
    public function vcard(Request $request, string $token): Response|RedirectResponse
    {
        $view = self::activeView($token);
        $p = $view->profile;
        $fresh = ! $request->boolean('file');
        if ($fresh && self::opensAndroidIntent((string) $request->userAgent())) {
            $this->markSavedContact($request, $view);

            return redirect()->away($this->androidIntent($view))->header('Cache-Control', 'private, no-store');
        }

        $vcard = (new VCard($p['display_name']))->org($p['company'])->title($p['title']);
        foreach ($view->fields as $f) {
            if (! $f['in_vcard']) {
                continue;
            }
            $type = ContactTypes::get($f['type']);
            if (! $type) {
                continue;
            }
            match ($type['vcard']) {
                'tel' => $vcard->phone($f['value'], str_starts_with($f['type'], 'whatsapp') ? 'CELL,WHATSAPP' : 'CELL'),
                'email' => $vcard->email($f['value']),
                'url', 'social' => $vcard->url(ContactValue::href($type, $f['value'], $f['meta'] ?? []) ?? $f['value']),
                'adr' => $vcard->address($f['value']),
                default => null,
            };
        }
        $vcard->note('Profil: '.config('tapcard.resolver_url').'/c/'.$view->token);
        if ($p['vcard_photo_path'] && Storage::disk('public')->exists($p['vcard_photo_path'])) {
            $vcard->photoJpeg(Storage::disk('public')->get($p['vcard_photo_path']));
        }

        if ($fresh) {
            $this->markSavedContact($request, $view);
        }

        return response($vcard->render(), 200, [
            'Content-Type' => 'text/vcard; charset=utf-8',
            'Content-Disposition' => 'inline; filename="'.$vcard->filename().'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** POST /api/c/{token}/connect — Tukar Kontak → lead (AFD F-08, BSD §8.4 contoh). */
    public function connect(Request $request, string $token, CurrentTenant $current, CreateConnection $create): JsonResponse
    {
        $view = self::activeView($token);
        abort_unless($view->profile['show_exchange_form'] ?? false, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'whatsapp' => ['nullable', 'string', 'max:32', 'required_without:email'],
            'email' => ['nullable', 'email:rfc', 'max:190', 'required_without:whatsapp'],
            'company' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
            'consent' => ['accepted'],
            'website' => ['nullable', 'string'],  // honeypot
            't' => ['nullable', 'string'],        // waktu render form (terenkripsi)
        ], [
            'whatsapp.required_without' => 'Isi WhatsApp atau email.',
            'email.required_without' => 'Isi WhatsApp atau email.',
            'consent.accepted' => 'Centang persetujuan untuk melanjutkan.',
        ]);

        if ($data['whatsapp'] ?? null) {
            if (! Phone::toE164($data['whatsapp'])) {
                return response()->json(['error' => ['code' => 'validation_failed', 'message' => 'Nomor WhatsApp belum valid.', 'fields' => ['whatsapp' => 'Nomor WhatsApp belum valid.']]], 422);
            }
        }

        // Spam → sukses palsu tanpa menyimpan agar bot tidak belajar (AFD F-08).
        if (! empty($data['website']) || self::tooFast($data['t'] ?? null)) {
            return response()->json(['data' => ['status' => 'sent']], 201);
        }

        $tenant = Tenant::findOrFail($view->tenantId);
        $current->run($tenant, function () use ($create, $data, $view, $tenant) {
            $eventId = EventForCard::at($tenant->id, $view->cardId, $view->holderUserId, now())?->id;
            try {
                $create->handle([
                    'name' => $data['name'],
                    'phone' => $data['whatsapp'] ?? null,
                    'email' => $data['email'] ?? null,
                    'company' => $data['company'] ?? null,
                    'note' => $data['note'] ?? null,
                    'consent' => true,
                ], LeadSource::ExchangeForm, $view->holderUserId, ['card_id' => $view->cardId, 'event_id' => $eventId]);
            } catch (ApiError $e) {
                // Batas paket pemilik tidak boleh membuat pengunjung gagal; cukup dicatat sebagai peringatan.
                Log::warning('Tukar Kontak tidak disimpan: '.$e->getMessage(), ['tenant_id' => $tenant->id, 'code' => $e->errorCode]);
            }
            ProductEvent::record('connect_submitted', $tenant->id, null, ['card' => $view->token]);
        });

        return response()->json(['data' => ['status' => 'sent']], 201);
    }

    /** GET /c/{token}/l/{field} — redirect tautan + hitung klik. */
    public function link(string $token, string $field): RedirectResponse
    {
        $view = self::activeView($token);
        $f = collect($view->fields)->firstWhere('id', $field);
        abort_if($f === null, 404);

        ProfileField::withoutGlobalScope(TenantScope::class)->where('public_id', $field)->increment('click_count');

        $type = ContactTypes::get($f['type']);
        $target = $type ? ContactValue::href($type, $f['value'], $f['meta'] ?? []) : null;
        abort_if($target === null, 404); // salin/teks saja: tidak ada tujuan

        return redirect()->away($target);
    }

    /** SVG QR kartu (dipakai layar QC & preview). Kartu digital Free: QR periode berjalan, jadi cache singkat. */
    public function qr(string $token): Response
    {
        $card = Card::acrossTenants()->where('card_token', $token)->firstOrFail();
        $rotating = ShareQr::rotationDays($card) !== null;

        return response(SvgRenderer::forUrl(ShareQr::url($card)), 200, [
            'Content-Type' => 'image/svg+xml', 'Cache-Control' => $rotating ? 'private, max-age=300' : 'public, max-age=86400',
        ]);
    }

    /** POST /api/shortcode — kode pendek 8 karakter → token kartu (P-06). */
    public function shortCode(Request $request): JsonResponse
    {
        $code = Crockford::normalize((string) $request->input('code'));
        $token = Crockford::isValid($code)
            ? Cache::remember("short:$code", 86400, fn () => Card::acrossTenants()->where('short_code', $code)->value('card_token'))
            : null;
        if (! $token) {
            throw new ApiError('shortcode_unknown', 'Kode belum dikenali. Periksa lagi 8 karakter di kartu.', 404);
        }

        return response()->json(['data' => ['token' => $token]]);
    }

    /** GET /api/handle/{handle} — tautan profil personal (WS-07): kartu aktif terbaru pengguna. */
    public function handle(string $handle): JsonResponse
    {
        $token = self::tokenForHandle($handle);
        if (! $token) {
            throw new ApiError('handle_unknown', 'Profil tidak ditemukan.', 404);
        }

        return response()->json(['data' => ['token' => $token]]);
    }

    /** GET tap.{base}/@{handle} — tautan pendek yang mudah diingat & dibagikan → bio kartu (dicatat sebagai `link`). */
    public function handleRedirect(string $handle, SpaController $spa): Response|RedirectResponse
    {
        $token = self::tokenForHandle($handle);

        // tidak dikenal → shell SPA (halaman "Profil tidak ditemukan") dengan status 404, bukan halaman error polos
        return $token ? redirect('/c/'.$token.'?src=link', 302) : $spa->render([], null, 404);
    }

    private static function tokenForHandle(string $handle): ?string
    {
        $user = User::where('handle', strtolower($handle))->whereNull('blocked_at')->first();

        return $user ? Card::forHandle($user)?->card_token : null;
    }

    /** Kartu aktif dengan profil (resolver publik); dipakai juga oleh PublicFormController. */
    public static function activeView(string $token): CardView
    {
        $view = CardView::cached($token);
        abort_if($view === null || $view->status !== 'active' || $view->profile === null || $view->tenantSuspended, 404);

        return $view;
    }

    /** Peramban Android yang menangani URL intent: (WebView aplikasi lain seperti Instagram/Facebook tidak). */
    public static function opensAndroidIntent(string $ua): bool
    {
        return str_contains($ua, 'Android')
            && (str_contains($ua, 'Chrome/') || str_contains($ua, 'SamsungBrowser/'))
            && ! preg_match('/; wv\)|FBAN|FBAV|FB_IAB|Instagram|Line\/|Snapchat|TikTok|musical_ly/i', $ua);
    }

    /** Intent ContactsContract.Intents.Insert: nama, telepon, email, perusahaan, jabatan, catatan (tanpa foto). */
    private function androidIntent(CardView $view): string
    {
        $p = $view->profile;
        $first = function (string $vcardKind) use ($view) {
            foreach ($view->fields as $f) {
                if ($f['in_vcard'] && (ContactTypes::get($f['type'])['vcard'] ?? null) === $vcardKind) {
                    return $f['value'];
                }
            }

            return null;
        };
        $profileUrl = config('tapcard.resolver_url').'/c/'.$view->token;
        $extras = array_filter([
            'name' => $p['display_name'],
            'phone' => $first('tel'),
            'email' => $first('email'),
            'company' => $p['company'],
            'job_title' => $p['title'],
            'notes' => 'Profil: '.$profileUrl,
            'browser_fallback_url' => "$profileUrl/vcard?file=1",
        ]);
        $intent = 'intent:#Intent;action=android.intent.action.INSERT;type=vnd.android.cursor.dir/raw_contact;';
        foreach ($extras as $k => $v) {
            $intent .= "S.$k=".rawurlencode((string) $v).';';
        }

        return $intent.'end';
    }

    /** Tandai tap terakhir pengunjung ini sebagai "simpan kontak" (metrik PRD §6). */
    private function markSavedContact(Request $request, CardView $view): void
    {
        $visitor = Hasher::visitor($request->ip(), $request->userAgent(), now('Asia/Jakarta')->toDateString());
        Tap::withoutGlobalScope(TenantScope::class)
            ->where('card_id', $view->cardId)->where('visitor_hash', $visitor)
            ->where('created_at', '>', now()->subHours(6))
            ->orderByDesc('created_at')->limit(1)
            ->get()->each(fn (Tap $t) => Tap::withoutGlobalScope(TenantScope::class)
            ->where('id', $t->id)->where('created_at', $t->created_at)->update(['saved_contact' => true]));
        ProductEvent::record('vcard_downloaded', $view->tenantId, null, ['card' => $view->token]);
    }

    /** Kiriman < 2 detik setelah form dirender dianggap bot (TRD §11). */
    public static function tooFast(?string $t): bool
    {
        try {
            return $t === null || (time() - (int) Crypt::decryptString($t)) < 2;
        } catch (Throwable) {
            return true;
        }
    }
}
