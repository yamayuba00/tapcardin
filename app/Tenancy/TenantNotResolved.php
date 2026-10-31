<?php

namespace App\Tenancy;

use RuntimeException;

final class TenantNotResolved extends RuntimeException
{
    public function __construct(?string $model = null)
    {
        parent::__construct($model
            ? "Query {$model} dijalankan tanpa konteks tenant. Gunakan withoutGlobalScope(TenantScope::class) secara eksplisit."
            : 'Konteks tenant belum diset.');
    }
}
