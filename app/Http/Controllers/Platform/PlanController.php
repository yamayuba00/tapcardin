<?php

namespace App\Http\Controllers\Platform;

use App\Authz\PlanFeatures;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Paket & harga (platform.plans.manage): harga bulanan/tahunan, batas, fitur, tampil di halaman harga.
 * Perubahan harga berlaku untuk tagihan berikutnya; batas & fitur langsung berlaku (cache paket tenant dibersihkan).
 * Paket yang dipakai tidak bisa dihapus — sembunyikan saja (is_public = false).
 */
class PlanController extends PlatformController
{
    public const LIMITS = [
        'members' => 'Anggota', 'cards_active' => 'Kartu aktif', 'cards_per_member' => 'Kartu per anggota', 'profiles_per_user' => 'Profil per pengguna',
        'connections' => 'Koneksi terlihat', 'qr_rotation_days' => 'QR kartu digital berganti (hari)', 'analytics_days' => 'Riwayat analitik (hari)', 'audit_days' => 'Audit log (hari)',
    ];

    public const FEATURES = [
        'pipeline' => 'Pipeline lead', 'reminders' => 'Pengingat follow-up', 'event_mode' => 'Mode Event', 'dynamic_qr' => 'QR aman (dinamis)',
        'subdomain' => 'Subdomain sendiri', 'custom_domain' => 'Domain kustom', 'teams' => 'Tim', 'custom_roles' => 'Peran kustom',
        'custom_stages' => 'Tahap pipeline kustom', 'lead_fields' => 'Field lead kustom', 'auto_assign' => 'Penugasan otomatis',
        'webhooks' => 'Webhook keluar', 'events_checkin' => 'Check-in event',
    ];

    public function index(): JsonResponse
    {
        $this->can('plans.manage');
        $tenants = Tenant::selectRaw('plan_id, count(*) as n')->groupBy('plan_id')->pluck('n', 'plan_id');
        $subs = Subscription::acrossTenants()->whereIn('status', ['active', 'trialing', 'past_due'])->selectRaw('plan_id, count(*) as n')->groupBy('plan_id')->pluck('n', 'plan_id');

        return $this->ok(Plan::orderBy('tenant_type')->orderBy('sort')->get()->map(fn (Plan $p) => self::present($p) + [
            'tenants_count' => (int) ($tenants[$p->id] ?? 0), 'subscriptions_count' => (int) ($subs[$p->id] ?? 0),
        ]), meta: ['limits' => self::LIMITS, 'features' => self::FEATURES]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->can('plans.manage');
        $data = $this->validated($request) + $request->validate([
            'key' => ['required', 'string', 'max:32', 'regex:/^[a-z0-9_]+$/', Rule::unique('plans', 'key')],
            'tenant_type' => ['required', Rule::in(['personal', 'company'])],
        ]);
        $plan = Plan::create($data + ['sort' => (Plan::max('sort') ?? 0) + 1]);
        Audit::platform('plan.created', $plan, null, self::present($plan));

        return $this->ok(self::present($plan), 201);
    }

    public function update(Request $request, Plan $plan): JsonResponse
    {
        $this->can('plans.manage');
        $before = self::present($plan);
        $plan->update($this->validated($request, partial: true));
        Tenant::where('plan_id', $plan->id)->each(fn (Tenant $t) => PlanFeatures::forget($t));
        Audit::platform('plan.updated', $plan, $before, self::present($plan));

        return $this->ok(self::present($plan));
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $s = $partial ? 'sometimes' : 'required';
        $rules = [
            'name' => [$s, 'string', 'max:60'],
            'price_monthly' => [$s, 'integer', 'min:0', 'max:100000000'],
            'price_yearly' => [$s, 'integer', 'min:0', 'max:1000000000'],
            'per_seat' => ['sometimes', 'boolean'],
            'trial_days' => ['sometimes', 'integer', 'min:0', 'max:90'],
            'is_public' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'limits' => ['sometimes', 'array'],
            'features' => ['sometimes', 'array'],
        ];
        foreach (array_keys(self::LIMITS) as $k) {
            $rules["limits.$k"] = ['nullable', 'integer', 'min:0']; // null = tanpa batas
        }
        foreach (array_keys(self::FEATURES) as $k) {
            $rules["features.$k"] = ['boolean'];
        }
        $data = $request->validate($rules);
        if (isset($data['limits'])) {
            $data['limits'] = array_intersect_key($data['limits'], self::LIMITS);
        }
        if (isset($data['features'])) {
            $data['features'] = array_map('boolval', array_intersect_key($data['features'], self::FEATURES));
        }

        return $data;
    }

    public static function present(Plan $p): array
    {
        return [
            'id' => $p->id, 'key' => $p->key, 'name' => $p->name, 'tenant_type' => $p->tenant_type,
            'price_monthly' => (int) $p->price_monthly, 'price_yearly' => (int) $p->price_yearly, 'per_seat' => (bool) $p->per_seat,
            'trial_days' => (int) $p->trial_days, 'is_public' => (bool) $p->is_public, 'sort' => (int) $p->sort,
            'limits' => array_merge(array_fill_keys(array_keys(self::LIMITS), null), $p->limits ?? []),
            'features' => array_merge(array_fill_keys(array_keys(self::FEATURES), false), $p->features ?? []),
        ];
    }
}
