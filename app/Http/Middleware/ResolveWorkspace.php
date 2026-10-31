<?php

namespace App\Http\Middleware;

use App\Enums\TenantType;
use App\Exceptions\ApiError;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\TenantMember;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantScope;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * TRD §4.6 — `app.` → workspace Personal milik pengguna; `{slug}.` → workspace Perusahaan;
 * domain kustom terverifikasi (tenant_domains, status active) → workspace pemilik domain itu.
 * Parameter host dihapus dari rute agar controller workspace sama untuk keduanya.
 */
class ResolveWorkspace
{
    public function __construct(private readonly CurrentTenant $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $custom = ! $request->route()->hasParameter('workspace');
        $slug = (string) $request->route('workspace');
        if (! $custom) {
            $request->route()->forgetParameter('workspace');
            URL::defaults(['workspace' => $slug]);
        }
        $domainTenant = $custom ? $this->tenantByDomain($request->getHost()) : null;
        abort_if($custom && ! $domainTenant, 404);

        $user = $request->user();
        if (! $user || ! $request->is('api/*')) {
            // Shell SPA & rute tamu tidak butuh tenant; data workspace hanya keluar lewat /api.
            if (! $custom && $slug !== 'app' && ! $request->is('api/*')) {
                $resolved = $this->companyBySlug($slug, $request);
                if ($resolved instanceof Response) {
                    return $resolved; // slug lama → 301 ke slug baru
                }
            }

            return $next($request);
        }

        if ($custom) {
            $tenant = $domainTenant;
            $isMember = TenantMember::withoutGlobalScope(TenantScope::class)
                ->where(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'status' => 'active'])->exists();
            abort_unless($isMember, 403, 'Anda bukan anggota workspace ini.');
        } elseif ($slug === 'app') {
            $tenant = $user->personalTenant;
        } else {
            $tenant = $this->companyBySlug($slug, $request);
            if ($tenant instanceof Response) {
                return $tenant;
            }
            $isMember = TenantMember::withoutGlobalScope(TenantScope::class)
                ->where(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'status' => 'active'])->exists();
            abort_unless($isMember, 403, 'Anda bukan anggota workspace ini.');
        }

        abort_if($tenant === null, 404);
        $this->current->set($tenant);
        if ($user->last_tenant_id !== $tenant->id) {
            $user->forceFill(['last_tenant_id' => $tenant->id])->saveQuietly();
        }

        if ($tenant->isSuspended() && ! $request->is('api/me', 'api/me/*')) {
            throw new ApiError('tenant_suspended', $tenant->suspend_reason ?: 'Workspace sementara dinonaktifkan. Data Anda tetap tersimpan.', 423);
        }

        return $next($request);
    }

    /** Domain kustom aktif → tenant (cache 5 menit; dihapus saat domain diubah di panel). */
    private function tenantByDomain(string $host): ?Tenant
    {
        $id = Cache::remember("tenant:domain:$host", 300, fn () => TenantDomain::where('domain', strtolower($host))->where('status', 'active')->value('tenant_id') ?? 0);

        return $id ? Tenant::find($id) : null;
    }

    private function companyBySlug(string $slug, Request $request): Tenant|Response
    {
        $id = Cache::remember("tenant:slug:$slug", 300, fn () => Tenant::where('slug', $slug)
            ->where('type', '!=', TenantType::Personal->value)->value('id'));
        if ($id) {
            return Tenant::findOrFail($id);
        }

        // slug lama → redirect 301 ke slug baru selama masa redirect (WS-04)
        $history = DB::table('tenant_slug_history')->where('slug', $slug)->where('redirect_until', '>', now())->first();
        if ($history && ($new = Tenant::find($history->tenant_id)?->slug)) {
            $url = str_replace("//$slug.", "//$new.", $request->fullUrl());

            return redirect()->away($url, 301);
        }

        abort(404);
    }
}
