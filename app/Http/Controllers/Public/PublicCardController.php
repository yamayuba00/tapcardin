<?php

namespace App\Http\Controllers\Public;

use App\Actions\Cards\CardView;
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
use App\Models\TenantMember;
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
    /** GET /c/{token}/vcard — vCard 3.0 (TRD §10.1). */
    public function vcard(Request $request, string $token): Response
    {
        $view = $this->activeView($token);
        $p = $view->profile;

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

        $this->markSavedContact($request, $view);

        return response($vcard->render(), 200, [
            'Content-Type' => 'text/vcard; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$vcard->filename().'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** POST /api/c/{token}/connect — Tukar Kontak → lead (AFD F-08, BSD §8.4 contoh). */
    public function connect(Request $request, string $token, CurrentTenant $current, CreateConnection $create): JsonResponse
    {
        $view = $this->activeView($token);
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
        if (! empty($data['website']) || $this->tooFast($data['t'] ?? null)) {
            return response()->json(['data' => ['status' => 'sent']], 201);
        }

        $tenant = Tenant::findOrFail($view->tenantId);
        $current->run($tenant, function () use ($create, $data, $view, $tenant) {
            $member = TenantMember::where('user_id', $view->holderUserId)->first();
            $eventId = $member?->active_event_until?->isFuture() ? $member->active_event_id : null;
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
        $view = $this->activeView($token);
        $f = collect($view->fields)->firstWhere('id', $field);
        abort_if($f === null, 404);

        ProfileField::withoutGlobalScope(TenantScope::class)->where('public_id', $field)->increment('click_count');

        $type = ContactTypes::get($f['type']);
        $target = $type ? ContactValue::href($type, $f['value'], $f['meta'] ?? []) : null;
        abort_if($target === null, 404); // salin/teks saja: tidak ada tujuan

        return redirect()->away($target);
    }

    /** SVG QR kartu (dipakai layar QC & preview). */
    public function qr(string $token): Response
    {
        $card = Card::acrossTenants()->where('card_token', $token)->firstOrFail();

        return response(SvgRenderer::forUrl($card->qrUrl()), 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'public, max-age=86400']);
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

    private function activeView(string $token): CardView
    {
        $view = CardView::cached($token);
        abort_if($view === null || $view->status !== 'active' || $view->profile === null || $view->tenantSuspended, 404);

        return $view;
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

    private function tooFast(?string $t): bool
    {
        try {
            return $t === null || (time() - (int) Crypt::decryptString($t)) < 2;
        } catch (Throwable) {
            return true;
        }
    }
}
