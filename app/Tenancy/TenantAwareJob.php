<?php

namespace App\Tenancy;

use App\Models\Tenant;

/**
 * Job yang dikirim dari konteks tenant menyimpan tenant_id dan memulihkan konteks di awal handle().
 * Kelas pemakai wajib punya properti publik `int $tenantId`.
 */
trait TenantAwareJob
{
    protected function enterTenant(): Tenant
    {
        $tenant = Tenant::findOrFail($this->tenantId);
        app(CurrentTenant::class)->set($tenant);

        return $tenant;
    }
}
