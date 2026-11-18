<?php

namespace App\Http\Controllers\Platform;

use App\Authz\PermissionResolver;
use App\Authz\PlanFeatures;
use App\Enums\TenantStatus;
use App\Enums\TenantType;
use App\Exceptions\ApiError;
use App\Models\Card;
use App\Models\Connection;
use App\Models\Invoice;
use App\Models\LeadStage;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\TenantMember;
use App\Models\User;
use App\Support\Audit;
use App\Support\Http\Table;
use App\Support\Notify;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** PL-02 — workspace: daftar, detail, ubah paket/batas, suspend/aktifkan, catatan internal. */
class TenantController extends PlatformController
{
    public function index(Request $request): JsonResponse
    {
        $this->can('tenants.view');
        $q = trim((string) $request->query('q'));
        $tenants = Tenant::with(['owner', 'plan'])
            ->when($q, fn ($w) => $w->where(fn ($x) => $x->where('name', 'ilike', "%$q%")->orWhere('slug', 'ilike', "%$q%")
                ->orWhereHas('owner', fn ($o) => $o->where('email', 'ilike', "%$q%"))))
            // tab Perusahaan mencakup semua workspace organisasi (company & partner)
            ->when($request->query('type'), fn ($w, $t) => $t === 'company' ? $w->where('type', '!=', TenantType::Personal->value) : $w->where('type', $t))
            ->when($request->query('status'), fn ($w, $s) => $w->where('status', $s));
        $sorts = ['name' => 'name', 'type' => 'type', 'status' => 'status', 'created_at' => 'created_at'];
        $page = Table::paginate($tenants, $request, $sorts, '-created_at');

        // kartu aktif & langganan untuk satu halaman sekaligus (tanpa N+1)
        $ids = $page->getCollection()->pluck('id');
        $cards = Card::acrossTenants()->whereIn('tenant_id', $ids)->where('form', Card::PHYSICAL)->whereIn('status', ['active', 'paused'])
            ->selectRaw('tenant_id, count(*) as n')->groupBy('tenant_id')->pluck('n', 'tenant_id');
        $subs = Subscription::acrossTenants()->whereIn('tenant_id', $ids)->get()->keyBy('tenant_id');

        return Table::json($page, fn (Tenant $t) => self::summary($t) + self::billing($t, (int) ($cards[$t->id] ?? 0), $subs[$t->id] ?? null), $request);
    }

    /** Kartu aktif / maksimal & masa aktif (akhir periode langganan atau uji coba). */
    public static function billing(Tenant $t, int $cardsActive, ?Subscription $sub): array
    {
        return [
            'cards_active' => $cardsActive,
            'cards_max' => PlanFeatures::limit($t, 'cards_active'),
            'subscription_status' => $sub?->status,
            'active_until' => $sub?->activePeriod()['end'],
        ];
    }

    /**
     * POST /api/tenants — buat workspace perusahaan; alamat {slug}.domain aktif langsung (tanpa DNS), mulai uji coba
     * paket (14 hari). Owner = pengguna yang sudah terdaftar.
     */
    public function store(Request $request): JsonResponse
    {
        $this->can('tenants.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'regex:/^[a-z0-9]([a-z0-9-]{1,28}[a-z0-9])$/', Rule::notIn(config('tapcard.reserved_slugs')), Rule::unique('tenants', 'slug')],
            'owner_email' => ['required', 'email'],
            'plan' => ['required', Rule::exists('plans', 'key')->where('tenant_type', 'company')],
        ], [
            'slug.regex' => 'Subdomain 3–30 karakter: huruf kecil, angka, dan strip.',
            'slug.not_in' => 'Subdomain ini dipakai sistem.',
            'slug.unique' => 'Subdomain ini sudah dipakai.',
        ]);
        $owner = User::where('email', strtolower(trim($data['owner_email'])))->first();
        if (! $owner) {
            throw new ApiError('owner_not_found', 'Owner belum terdaftar. Minta yang bersangkutan mendaftar dulu.', 422);
        }

        $plan = Plan::where('key', $data['plan'])->firstOrFail();
        $tenant = DB::transaction(function () use ($data, $owner, $plan) {
            $tenant = Tenant::create([
                'type' => TenantType::Company, 'name' => $data['name'], 'slug' => $data['slug'], 'owner_user_id' => $owner->id,
                'status' => TenantStatus::Active, 'plan_id' => $plan->id, 'settings' => Tenant::DEFAULT_SETTINGS,
            ]);
            app(CurrentTenant::class)->run($tenant, function () use ($tenant, $owner, $plan) {
                TenantMember::create(['tenant_id' => $tenant->id, 'user_id' => $owner->id, 'role_id' => Role::system('owner')->id, 'status' => 'active']);
                foreach (LeadStage::DEFAULTS as $i => $stage) {
                    LeadStage::create([...$stage, 'sort' => $i]);
                }
                // uji coba gratis; setelah itu bayar per 6 bulan dari halaman Paket workspace
                $end = now()->addDays($plan->trial_days ?: 14);
                Subscription::create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'cycle' => 'semiannual', 'status' => 'trialing',
                    'trial_ends_at' => $end, 'current_period_start' => now(), 'current_period_end' => $end]);
            });

            return $tenant;
        });
        Audit::platform('tenant.created', $tenant, null, ['slug' => $tenant->slug, 'owner' => $owner->public_id]);

        return $this->show($tenant->fresh(), 201);
    }

    /**
     * PUT /api/tenants/{id}/owner — pindah kepemilikan workspace perusahaan ke akun terdaftar.
     * Owner baru otomatis jadi anggota berperan Owner; owner lama tetap anggota sebagai Admin.
     */
    public function transferOwner(Request $request, Tenant $tenant): JsonResponse
    {
        $this->can('tenants.manage');
        if ($tenant->type === TenantType::Personal) {
            throw new ApiError('owner_fixed', 'Pemilik workspace personal tidak bisa dipindahkan.', 422);
        }
        $data = $request->validate(['user' => ['required', 'string', 'size:26']]);
        $new = User::where('public_id', $data['user'])->first();
        if (! $new || $new->isBlocked()) {
            throw new ApiError('owner_invalid', 'Akun tidak ditemukan atau sedang diblokir.', 422);
        }
        if ($new->id === $tenant->owner_user_id) {
            throw new ApiError('owner_same', 'Akun ini sudah menjadi owner.', 422);
        }

        $previous = $tenant->owner_user_id;
        DB::transaction(function () use ($tenant, $new, $previous) {
            $tenant->update(['owner_user_id' => $new->id]);
            app(CurrentTenant::class)->run($tenant, function () use ($tenant, $new, $previous) {
                TenantMember::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'user_id' => $new->id],
                    ['role_id' => Role::system('owner')->id, 'status' => 'active'],
                );
                TenantMember::where('tenant_id', $tenant->id)->where('user_id', $previous)->update(['role_id' => Role::system('admin')->id]);
            });
        });
        PermissionResolver::bump($tenant->id);
        Audit::platform('tenant.owner_changed', $tenant, ['owner' => User::whereKey($previous)->value('public_id')], ['owner' => $new->public_id]);

        return $this->show($tenant->fresh());
    }

    /** GET /api/tenants/{id}/members */
    public function members(Request $request, Tenant $tenant): JsonResponse
    {
        $this->can('tenants.view');
        $q = TenantMember::acrossTenants()->with(['user', 'role'])->where('tenant_members.tenant_id', $tenant->id)
            ->join('users', 'users.id', '=', 'tenant_members.user_id')->select('tenant_members.*');
        $sorts = ['name' => 'users.name', 'email' => 'users.email', 'status' => 'tenant_members.status', 'joined_at' => 'tenant_members.joined_at'];

        return Table::json(Table::paginate($q, $request, $sorts, 'joined_at', ['users.name', 'users.email'], 10),
            fn ($m) => ['user_id' => $m->user->public_id, 'name' => $m->user->name, 'email' => $m->user->email, 'role' => $m->role->name, 'status' => $m->status->value], $request);
    }

    /** GET /api/tenants/{id}/cards */
    public function cards(Request $request, Tenant $tenant): JsonResponse
    {
        $this->can('tenants.view');
        $q = Card::acrossTenants()->with(['holder', 'batch'])->where('tenant_id', $tenant->id)
            ->when($request->query('status'), fn ($w, $s) => $w->where('status', $s));

        return Table::json(Table::paginate($q, $request, CardController::SORTS, '-updated_at', ['card_token', 'short_code'], 10),
            fn (Card $c) => CardController::summary($c), $request);
    }

    public function show(Tenant $tenant, int $status = 200): JsonResponse
    {
        $this->can('tenants.view');
        $tenant->load(['owner', 'plan']);

        return $this->ok(self::summary($tenant) + [
            'internal_note' => $tenant->internal_note,
            'suspend_reason' => $tenant->suspend_reason,
            'plan_id' => $tenant->plan_id,
            'limit_overrides' => $tenant->limit_overrides ?: (object) [],
            'limits' => PlanFeatures::effective($tenant)['limits'],
            'usage' => [
                'members' => TenantMember::acrossTenants()->where('tenant_id', $tenant->id)->where('status', 'active')->count(),
                'cards_active' => Card::acrossTenants()->where('tenant_id', $tenant->id)->where('form', Card::PHYSICAL)->whereIn('status', ['active', 'paused'])->count(),
                'connections' => Connection::acrossTenants()->where('tenant_id', $tenant->id)->count(),
            ],
            'plans' => Plan::where('tenant_type', $tenant->type === TenantType::Personal ? 'personal' : 'company')->orderBy('sort')
                ->get(['id', 'key', 'name'])->map(fn ($p) => ['id' => $p->id, 'key' => $p->key, 'name' => $p->name]),
            'features' => PlanFeatures::effective($tenant)['features'] ?? [],
            'subscription' => ($s = Subscription::acrossTenants()->with('plan')->where('tenant_id', $tenant->id)->first()) ? FinanceController::subscriptionRow($s) : null,
            'active_period' => $s?->activePeriod(),
            'invoices' => Invoice::acrossTenants()->with('plan')->where('tenant_id', $tenant->id)->latest('id')->limit(5)->get()
                ->map(fn (Invoice $i) => FinanceController::invoiceRow($i)),
            'domains' => TenantDomain::with('tenant')->where('tenant_id', $tenant->id)->orderBy('domain')->get()
                ->map(fn (TenantDomain $d) => DomainController::present($d)),
        ], $status);
    }

    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        $this->can('tenants.manage');
        $data = $request->validate([
            'plan_id' => ['sometimes', Rule::exists('plans', 'id')],
            'internal_note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'limit_cards_active' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
            'limit_connections' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);
        $before = $tenant->only(['plan_id', 'limit_overrides']);
        $overrides = $tenant->limit_overrides ?? [];
        foreach (['cards_active', 'connections'] as $k) {
            if ($request->has("limit_$k")) {
                $v = $data["limit_$k"] ?? null;
                $v === null ? Arr::forget($overrides, $k) : $overrides[$k] = (int) $v;
            }
        }
        $tenant->fill(array_intersect_key($data, array_flip(['plan_id', 'internal_note'])));
        $tenant->limit_overrides = $overrides;
        $tenant->save();
        PlanFeatures::forget($tenant);
        Audit::platform('tenant.updated', $tenant, $before, $tenant->only(['plan_id', 'limit_overrides']));

        return $this->show($tenant->fresh());
    }

    public function suspend(Request $request, Tenant $tenant): JsonResponse
    {
        $this->can('tenants.suspend');
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']], ['reason.required' => 'Alasan wajib diisi.']);
        $tenant->update(['status' => TenantStatus::Suspended, 'suspended_at' => now(), 'suspend_reason' => $data['reason']]);
        $this->forgetCache($tenant);
        Audit::platform('tenant.suspended', $tenant, null, ['reason' => $data['reason']]);

        return $this->show($tenant->fresh());
    }

    public function reactivate(Tenant $tenant): JsonResponse
    {
        $this->can('tenants.suspend');
        $tenant->update(['status' => TenantStatus::Active, 'suspended_at' => null, 'suspend_reason' => null]);
        $this->forgetCache($tenant);
        Audit::platform('tenant.reactivated', $tenant);

        return $this->show($tenant->fresh());
    }

    public static function summary(Tenant $t): array
    {
        return [
            'id' => $t->public_id,
            'name' => $t->name,
            'type' => $t->type->value,
            'slug' => $t->slug,
            'url' => $t->slug ? Notify::baseUrl($t) : null,
            'status' => $t->status->value,
            'plan' => $t->plan?->name,
            'owner' => $t->owner ? ['id' => $t->owner->public_id, 'email' => $t->owner->email] : null,
            'created_at' => self::dt($t->created_at),
        ];
    }

    private function forgetCache(Tenant $tenant): void
    {
        if ($tenant->slug) {
            Cache::forget("tenant:slug:{$tenant->slug}");
        }
        Card::acrossTenants()->where('tenant_id', $tenant->id)->pluck('card_token')->each(function ($t) {
            Cache::forget("card:$t");
        }); // bukan arrow fn: forget() = false menghentikan each()
    }
}
