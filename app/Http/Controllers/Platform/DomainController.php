<?php

namespace App\Http\Controllers\Platform;

use App\Authz\PlanFeatures;
use App\Exceptions\ApiError;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Support\Audit;
use App\Support\Dns\DnsResolver;
use App\Support\Http\Table;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Domain kustom workspace (platform.tenants.manage). Alur: tambah → pelanggan memasang TXT `_tapcard.{domain}` =
 * `tapcard-verify={token}` dan CNAME ke domain aplikasi → Verifikasi (cek DNS) → aktif. Workspace lalu bisa dibuka
 * lewat domain itu (lihat ResolveWorkspace). Pemilik platform bisa mengaktifkan manual (mis. domain internal).
 */
class DomainController extends PlatformController
{
    public function index(Request $request): JsonResponse
    {
        $this->can('tenants.view');
        $q = TenantDomain::with('tenant')
            ->when($request->query('tenant'), fn ($w, $t) => $w->whereHas('tenant', fn ($x) => $x->where('public_id', $t)))
            ->when($request->query('status'), fn ($w, $s) => $w->where('status', $s));

        return Table::json(Table::paginate($q, $request, ['domain' => 'domain', 'created_at' => 'created_at', 'status' => 'status'], '-created_at', ['domain'], 25),
            fn (TenantDomain $d) => self::present($d), $request);
    }

    public function store(Request $request): JsonResponse
    {
        $this->can('tenants.manage');
        $data = $request->validate([
            'tenant' => ['required', 'string', 'size:26'],
            'domain' => ['required', 'string', 'max:190'],
        ]);
        $domain = strtolower(trim(preg_replace('#^https?://#i', '', rtrim($data['domain'], '/'))));
        $base = config('tapcard.base_domain');
        $request->merge(['domain' => $domain])->validate([
            'domain' => ['regex:/^(?=.{4,190}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', Rule::unique('tenant_domains', 'domain')],
        ], ['domain.regex' => 'Tulis nama domain saja, mis. kartu.perusahaan.com.', 'domain.unique' => 'Domain ini sudah terdaftar.']);
        if ($domain === $base || str_ends_with($domain, '.'.$base)) {
            throw new ApiError('domain_reserved', 'Subdomain TapCard tidak bisa dipakai sebagai domain kustom.');
        }
        $tenant = Tenant::where('public_id', $data['tenant'])->firstOrFail();
        $d = TenantDomain::create(['tenant_id' => $tenant->id, 'domain' => $domain, 'verify_token' => Str::random(32), 'created_by' => auth()->id()]);
        Audit::platform('domain.added', $d, null, ['domain' => $domain, 'tenant' => $tenant->public_id]);

        return $this->ok(self::present($d->fresh('tenant')) + ['plan_allows' => PlanFeatures::has($tenant, 'custom_domain')], 201);
    }

    /** POST /api/domains/{id}/verify — cek TXT; cocok → aktif. */
    public function verify(TenantDomain $domain, DnsResolver $dns): JsonResponse
    {
        $this->can('tenants.manage');
        $found = $dns->txt($domain->txtName());
        $ok = in_array($domain->txtValue(), array_map('trim', $found), true);
        $domain->update([
            'last_checked_at' => now(),
            'last_error' => $ok ? null : ($found ? 'TXT ditemukan tetapi nilainya tidak cocok.' : 'TXT record belum ditemukan (propagasi DNS bisa sampai 24 jam).'),
            ...($ok ? ['status' => 'active', 'verified_at' => now()] : []),
        ]);
        $this->forget($domain);
        if ($ok) {
            Audit::platform('domain.verified', $domain, null, ['domain' => $domain->domain]);
        }

        return $this->ok(self::present($domain->load('tenant')));
    }

    /** PATCH /api/domains/{id} {status} — aktifkan manual (hanya pemilik platform) / nonaktifkan. */
    public function update(Request $request, TenantDomain $domain): JsonResponse
    {
        $this->can('tenants.manage');
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'disabled'])]]);
        if ($data['status'] === 'active' && ! $domain->verified_at) {
            $this->can('system.manage'); // tanpa verifikasi DNS hanya untuk pemilik platform
        }
        $domain->update(['status' => $data['status'], 'verified_at' => $domain->verified_at ?? ($data['status'] === 'active' ? now() : null)]);
        $this->forget($domain);
        Audit::platform('domain.'.$data['status'], $domain, null, ['domain' => $domain->domain]);

        return $this->ok(self::present($domain->load('tenant')));
    }

    public function destroy(TenantDomain $domain): JsonResponse
    {
        $this->can('tenants.manage');
        $this->forget($domain);
        Audit::platform('domain.removed', $domain, ['domain' => $domain->domain]);
        $domain->delete();

        return response()->json(null, 204);
    }

    private function forget(TenantDomain $d): void
    {
        Cache::forget("tenant:domain:{$d->domain}");
    }

    public static function present(TenantDomain $d): array
    {
        return [
            'id' => $d->public_id, 'domain' => $d->domain, 'status' => $d->status,
            'tenant' => $d->tenant ? ['id' => $d->tenant->public_id, 'name' => $d->tenant->name] : null,
            'dns' => [
                ['type' => 'TXT', 'name' => $d->txtName(), 'value' => $d->txtValue()],
                ['type' => 'CNAME', 'name' => $d->domain, 'value' => 'app.'.config('tapcard.base_domain')],
            ],
            'verified_at' => self::dt($d->verified_at), 'last_checked_at' => self::dt($d->last_checked_at), 'last_error' => $d->last_error,
            'created_at' => self::dt($d->created_at),
        ];
    }
}
