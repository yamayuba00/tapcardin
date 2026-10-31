<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $id = app(CurrentTenant::class)->id();
        if ($id === null) {
            // Di luar konteks tenant (konsol, job platform) query wajib eksplisit memakai withoutGlobalScope.
            throw new TenantNotResolved($model::class);
        }
        $builder->where($model->qualifyColumn('tenant_id'), $id);
    }
}
