<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);
        static::creating(function ($model) {
            if ($model->tenant_id !== null) {
                return;
            }
            $current = app(CurrentTenant::class)->id();
            if ($current === null && ($model->tenantOptional ?? false)) {
                return; // mis. kartu stok tanpa pesanan
            }
            $model->tenant_id = $current ?? throw new TenantNotResolved($model::class);
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Query lintas tenant — hanya untuk kode platform, resolver, dan scheduler. */
    public static function acrossTenants(): Builder
    {
        return static::withoutGlobalScope(TenantScope::class);
    }
}
