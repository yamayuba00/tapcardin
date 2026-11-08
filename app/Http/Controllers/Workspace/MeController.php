<?php

namespace App\Http\Controllers\Workspace;

use App\Actions\Auth\DeleteAccount;
use App\Actions\Cards\CreateDigitalCard;
use App\Authz\PlanFeatures;
use App\Http\Resources\Present;
use App\Models\BioPage;
use App\Models\Card;
use App\Models\Connection;
use App\Models\Invoice;
use App\Models\PlatformStaff;
use App\Models\Profile;
use App\Models\Tap;
use App\Models\TenantMember;
use App\Support\Mail\EmailContent;
use App\Support\Phone;
use App\Support\TapQuota;
use App\Tenancy\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** GET /api/me, pengaturan akun & privasi (U-15, PV-02). */
class MeController extends WorkspaceController
{
    public const NOTIFICATION_TYPES = [
        'tap' => 'Kartu di-tap',
        'lead_new' => 'Koneksi baru (Tukar Kontak)',
        'lost_card_tapped' => 'Kartu hilang di-tap',
        'reminder_due' => 'Pengingat follow-up jatuh tempo',
        'saved_back' => 'Seseorang menyimpan Anda (Mode Reader)',
        'import_done' => 'Impor CSV selesai',
        'invoice_paid' => 'Pembayaran berhasil',
        'invoice_due' => 'Tagihan perpanjangan paket',
        'subscription_past_due' => 'Paket masuk masa tenggang',
        'grace_ending' => 'Masa tenggang hampir berakhir',
        'plan_changed' => 'Perubahan paket',
        'order_status' => 'Status pesanan kartu',
        'tap_quota' => 'Kuota tap kartu digital hampir / sudah habis',
    ];

    /** Data halaman pengaturan: preferensi notifikasi, paket & pemakaian, izin efektif. */
    public function settings(): JsonResponse
    {
        $u = $this->user();

        return response()->json(['data' => [
            'notification_types' => self::NOTIFICATION_TYPES,
            'prefs' => collect(self::NOTIFICATION_TYPES)->map(fn ($_, $k) => [
                'inapp' => $u->wantsNotification($k, 'inapp'),
                'email' => $u->wantsNotification($k, 'email', EmailContent::defaultOn($k)),
            ]),
            'plan' => [
                'name' => $this->tenant()->plan?->name,
                'limits' => PlanFeatures::effective($this->tenant())['limits'],
                'usage' => [
                    'cards_active' => Card::where('form', Card::PHYSICAL)->whereIn('status', ['active', 'paused'])->count(),
                    'profiles_per_user' => Profile::where('user_id', $u->id)->whereNull('archived_at')->count(),
                    'connections' => Connection::count(),
                ],
            ],
            'digital_card' => $this->digitalCard(),
            'deletion' => [
                'retention_days' => DeleteAccount::RETENTION_DAYS,
                'cards' => Card::acrossTenants()->where('holder_user_id', $u->id)->where('status', '!=', 'revoked')->count(),
                'profiles' => Profile::acrossTenants()->where('user_id', $u->id)->count(),
                'connections' => $u->personal_tenant_id ? Connection::acrossTenants()->where('tenant_id', $u->personal_tenant_id)->count() : 0,
            ],
            'permissions' => $this->access()->list(),
        ]]);
    }

    /** Paket kartu digital (terpisah dari paket workspace): kuota tap, bio link, dan harga tambahan tap. */
    private function digitalCard(): ?array
    {
        $card = CreateDigitalCard::of($this->user(), $this->tenant());
        if (! $card) {
            return null;
        }
        $c = config('tapcard.digital_card');
        $open = Invoice::where('type', 'topup')->where('status', 'issued')->where('expires_at', '>', now())
            ->whereRaw("(billing_snapshot->>'card_id')::bigint = ?", [$card->id])->latest('id')->first();

        return [
            'token' => $card->card_token, 'status' => $card->status->value,
            'taps' => TapQuota::of($card),
            'bio_pages' => ['used' => $card->profile_id ? BioPage::where('profile_id', $card->profile_id)->count() : 0, 'limit' => $c['bio_pages']],
            'topup' => ['taps' => $c['topup_taps'], 'price' => $c['topup_price']],
            'open_invoice' => $open?->public_id,
        ];
    }

    public function show(): JsonResponse
    {
        $tenant = $this->tenant();

        return response()->json(['data' => $this->me() + [
            'workspace' => [
                'id' => $tenant->public_id,
                'name' => $tenant->name,
                'type' => $tenant->type->value,
                'plan' => $tenant->plan?->key,
                'plan_name' => $tenant->plan?->name,
                'role' => $this->access()->context()['role'],
                'contact_label' => $tenant->contactLabel(),
                'lost_reasons' => $tenant->setting('lost_reasons'),
            ],
            'workspaces' => $this->workspaces(),
            'permissions' => $this->access()->list(),
            'is_staff' => PlatformStaff::where('user_id', $this->user()->id)->where('status', 'active')->exists(),
        ]]);
    }

    /** RL-09 — "Apa yang bisa saya lakukan?" */
    public function permissions(): JsonResponse
    {
        return response()->json(['data' => $this->access()->list()]);
    }

    public function update(Request $request): JsonResponse
    {
        $u = $this->user();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'handle' => ['sometimes', 'nullable', 'string', 'min:3', 'max:30', 'regex:/^[a-z0-9_.]+$/',
                Rule::unique('users', 'handle')->ignore($u->id), Rule::notIn(config('tapcard.reserved_slugs'))],
            'locale' => ['sometimes', Rule::in(['id', 'en'])],
            'notification_prefs' => ['sometimes', 'array'],
            'notification_prefs.*.inapp' => ['boolean'],
            'notification_prefs.*.email' => ['boolean'],
        ], ['handle.regex' => 'Gunakan huruf kecil, angka, titik, atau garis bawah.', 'handle.unique' => 'Nama pengguna sudah dipakai.']);

        if (isset($data['notification_prefs'])) {
            $data['notification_prefs'] = array_intersect_key($data['notification_prefs'], self::NOTIFICATION_TYPES);
        }
        if (isset($data['phone'])) {
            $data['phone'] = Phone::toE164($data['phone']);
        }
        $u->update($data);

        return response()->json(['data' => $this->me()]);
    }

    /** PV-02 — ekspor data pribadi (JSON). */
    public function export(): StreamedResponse
    {
        $u = $this->user();
        $tid = $u->personal_tenant_id;
        $q = fn (string $model) => $model::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tid);

        $payload = [
            'exported_at' => now()->toIso8601String(),
            'account' => $u->only(['name', 'email', 'phone', 'handle', 'locale', 'timezone', 'created_at']),
            'profiles' => $q(Profile::class)->where('user_id', $u->id)->with(['fields' => fn ($f) => $f->withoutGlobalScopes()])->get()
                ->map(fn ($p) => Present::profile($p, true)),
            'cards' => $q(Card::class)->where('holder_user_id', $u->id)->get()->map(fn ($c) => Present::card($c)),
            'connections' => $q(Connection::class)->with(['stage' => fn ($s) => $s->withoutGlobalScopes()])->get()->map(fn ($c) => Present::connection($c)),
            'taps' => $q(Tap::class)->where('holder_user_id', $u->id)->where('created_at', '>=', now()->subMonths(24))
                ->orderBy('created_at')->get(['source', 'country', 'city', 'device', 'saved_contact', 'created_at']),
        ];

        return response()->streamDownload(
            fn () => print (json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'tapcard-data-'.now('Asia/Jakarta')->format('Ymd').'.json',
            ['Content-Type' => 'application/json'],
        );
    }

    /** POST /api/me/onboarding {dismissed} — tutup / tampilkan lagi panduan pengguna baru di Beranda. */
    public function onboarding(Request $request): JsonResponse
    {
        $data = $request->validate(['dismissed' => ['required', 'boolean']]);
        $u = $this->user();
        $prefs = $u->preferences ?? [];
        $prefs['onboarding_dismissed_at'] = $data['dismissed'] ? now()->toIso8601String() : null;
        $u->forceFill(['preferences' => array_filter($prefs, fn ($v) => $v !== null)])->save();

        return response()->json(null, 204);
    }

    /** PV-02 / AFD F-15 — hapus akun (skema lengkap: DeleteAccount). */
    public function destroy(Request $request, DeleteAccount $delete): JsonResponse
    {
        $u = $this->user();
        $request->validate(['confirm_email' => ['required', Rule::in([$u->email])]], ['confirm_email.in' => 'Ketik email akun Anda persis untuk konfirmasi.']);

        $delete->handle($u);
        Auth::logout();
        $request->session()->invalidate();

        return response()->json(['data' => ['redirect' => '/login']]);
    }

    private function me(): array
    {
        $u = $this->user();

        return [
            'id' => $u->public_id,
            'name' => $u->name,
            'email' => $u->email,
            'phone' => $u->phone,
            'handle' => $u->handle,
            'locale' => $u->locale,
            'google_linked' => $u->google_id !== null,
        ];
    }

    private function workspaces(): array
    {
        return TenantMember::withoutGlobalScope(TenantScope::class)->with(['role', 'tenant'])
            ->where('user_id', $this->user()->id)->where('status', 'active')->get()
            ->map(fn ($m) => ['name' => $m->tenant->name, 'type' => $m->tenant->type->value, 'slug' => $m->tenant->slug ?? 'app', 'role' => $m->role->name])
            ->all();
    }
}
