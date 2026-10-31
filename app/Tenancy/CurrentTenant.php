<?php

namespace App\Tenancy;

use App\Models\Tenant;

/** Satu instance per request (scoped singleton) — TRD §4.5. */
final class CurrentTenant
{
    private ?Tenant $tenant = null;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function require(): Tenant
    {
        return $this->tenant ?? throw new TenantNotResolved;
    }

    /**
     * Jalankan callback dalam konteks tenant tertentu lalu kembalikan konteks semula.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(Tenant $tenant, callable $callback): mixed
    {
        $previous = $this->tenant;
        $this->tenant = $tenant;
        try {
            return $callback();
        } finally {
            $this->tenant = $previous;
        }
    }
}
