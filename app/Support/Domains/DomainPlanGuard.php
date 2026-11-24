<?php

namespace App\Support\Domains;

use App\Authz\PlanFeatures;
use App\Models\Tenant;
use App\Models\TenantDomain;
use Illuminate\Support\Facades\Cache;

/** Domain kustom hanya aktif selama paket workspace mencakup fitur `custom_domain`. */
final class DomainPlanGuard
{
    public const LOST_REASON = 'Paket tidak mencakup Domain kustom. Upgrade paket untuk mengaktifkan kembali.';

    /** Dipanggil saat paket tenant berubah: domain aktif yang kehilangan fitur langsung dinonaktifkan. */
    public static function sync(Tenant $tenant): void
    {
        PlanFeatures::forget($tenant);
        if (PlanFeatures::has($tenant, 'custom_domain')) {
            return;
        }
        TenantDomain::where('tenant_id', $tenant->id)->where('status', 'active')->get()->each(function (TenantDomain $d) {
            $d->update(['status' => 'disabled', 'last_error' => self::LOST_REASON]);
            Cache::forget("tenant:domain:{$d->domain}");
        });
    }
}
