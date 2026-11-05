<?php

namespace App\Http\Controllers\Platform;

use App\Authz\PlanFeatures;
use App\Enums\TenantStatus;
use App\Enums\TenantType;
use App\Models\Card;
use App\Models\Connection;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\TenantMember;
use App\Support\Audit;
use App\Support\Http\Table;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
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
            ->when($request->query('type'), fn ($w, $t) => $w->where('type', $t))
            ->when($request->query('status'), fn ($w, $s) => $w->where('status', $s));
        $sorts = ['name' => 'name', 'type' => 'type', 'status' => 'status', 'created_at' => 'created_at'];

        return Table::json(Table::paginate($tenants, $request, $sorts, '-created_at'), fn (Tenant $t) => self::summary($t), $request);
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

    public function show(Tenant $tenant): JsonResponse
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
            'invoices' => Invoice::acrossTenants()->with('plan')->where('tenant_id', $tenant->id)->latest('id')->limit(5)->get()
                ->map(fn (Invoice $i) => FinanceController::invoiceRow($i)),
            'domains' => TenantDomain::with('tenant')->where('tenant_id', $tenant->id)->orderBy('domain')->get()
                ->map(fn (TenantDomain $d) => DomainController::present($d)),
        ]);
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
