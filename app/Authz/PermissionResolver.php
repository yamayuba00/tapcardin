<?php

namespace App\Authz;

use App\Models\Permission;
use App\Models\PlatformStaff;
use App\Models\RolePermission;
use App\Models\TeamMember;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use App\Tenancy\TenantScope;
use Illuminate\Support\Facades\Cache;

/** TRD §5.3 — izin efektif = izin peran ∩ fitur paket, di-cache per versi `permver`. */
final class PermissionResolver
{
    /** @return array{perms: array<string,true>, team_ids: int[], managed_team_ids: int[], role: ?string} */
    public function for(User $user, Tenant $tenant): array
    {
        $ver = Cache::get("t{$tenant->id}:permver", 0);

        return Cache::remember("t{$tenant->id}:perm:{$user->id}:v{$ver}", 600, function () use ($user, $tenant) {
            $member = TenantMember::withoutGlobalScope(TenantScope::class)
                ->with('role')
                ->where(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'status' => 'active'])
                ->first();
            if (! $member) {
                return ['perms' => [], 'team_ids' => [], 'managed_team_ids' => [], 'role' => null];
            }
            $features = PlanFeatures::for($tenant);
            $perms = RolePermission::where('role_id', $member->role_id)->pluck('permission_key')
                ->filter(fn ($k) => PlanFeatures::permissionAllowed($k, $features))
                ->values()->flip()->map(fn () => true)->all();
            $teams = TeamMember::withoutGlobalScope(TenantScope::class)
                ->where(['tenant_id' => $tenant->id, 'user_id' => $user->id])->get();

            return [
                'perms' => $perms,
                'team_ids' => $teams->pluck('team_id')->all(),
                'managed_team_ids' => $teams->where('is_manager', true)->pluck('team_id')->values()->all(),
                'role' => $member->role?->key ?? $member->role?->name,
            ];
        });
    }

    /** @return array<string,true> */
    public function forStaff(User $user): array
    {
        return Cache::remember("platform:perm:{$user->id}:v".Cache::get('platform:permver', 0), 600, function () use ($user) {
            $staff = PlatformStaff::where('user_id', $user->id)->where('status', 'active')->first();
            if (! $staff) {
                return [];
            }

            return RolePermission::where('role_id', $staff->role_id)->pluck('permission_key')
                ->flip()->map(fn () => true)->all();
        });
    }

    /** Naikkan versi → cache izin semua anggota tenant itu usang. */
    public static function bump(int $tenantId): void
    {
        Cache::forever("t{$tenantId}:permver", Cache::get("t{$tenantId}:permver", 0) + 1);
    }

    public static function bumpPlatform(): void
    {
        Cache::forever('platform:permver', Cache::get('platform:permver', 0) + 1);
    }

    /** @return array<string, ?string> permission key => plan_feature */
    public static function featureMap(): array
    {
        return Cache::remember('authz:feature-map', 3600, fn () => Permission::pluck('plan_feature', 'key')->all());
    }
}
