<?php

namespace App\Authz;

use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;

/** TRD §4.7 — fitur & batas efektif = plans.features/limits digabung tenants.limit_overrides. */
final class PlanFeatures
{
    /** @return array{features: array<string,bool>, limits: array<string,?int>} */
    public static function effective(Tenant $tenant): array
    {
        return Cache::remember("t{$tenant->id}:plan", 600, function () use ($tenant) {
            $plan = $tenant->plan;
            $overrides = $tenant->limit_overrides ?? [];

            return [
                'features' => array_merge($plan?->features ?? [], $overrides['features'] ?? []),
                'limits' => array_merge($plan?->limits ?? [], array_diff_key($overrides, ['features' => 1])),
            ];
        });
    }

    /** @return array<string,bool> */
    public static function for(Tenant $tenant): array
    {
        return self::effective($tenant)['features'];
    }

    public static function has(Tenant $tenant, string $feature): bool
    {
        return (bool) (self::for($tenant)[$feature] ?? false);
    }

    /** null = tanpa batas. */
    public static function limit(Tenant $tenant, string $key): ?int
    {
        return self::effective($tenant)['limits'][$key] ?? null;
    }

    public static function permissionAllowed(string $permission, array $features): bool
    {
        $feature = PermissionResolver::featureMap()[$permission] ?? null;

        return $feature === null || ! empty($features[$feature]);
    }

    public static function forget(Tenant $tenant): void
    {
        Cache::forget("t{$tenant->id}:plan");
        PermissionResolver::bump($tenant->id);
    }
}
