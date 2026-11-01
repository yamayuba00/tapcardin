<?php

namespace App\Authz;

use App\Models\PlatformStaff;

/**
 * Siapa saja staf aktif yang berwenang untuk suatu izin platform. Dipakai aturan "dua orang":
 * pengaju dan penyetuju harus berbeda. Hanya bila TIDAK ADA staf lain yang berwenang (mis. baru satu Pemilik),
 * pengaju boleh menyetujui sendiri — tercatat sebagai `self_approved` agar terlihat di audit.
 */
final class Approvers
{
    /** @return list<int> id pengguna staf aktif (tidak diblokir) yang punya izin; opsional kecualikan satu pengguna. */
    public static function userIds(string $permission, ?int $except = null): array
    {
        return PlatformStaff::query()
            ->join('role_permissions', 'role_permissions.role_id', '=', 'platform_staff.role_id')
            ->join('users', 'users.id', '=', 'platform_staff.user_id')
            ->where('platform_staff.status', 'active')
            ->whereNull('users.blocked_at')->whereNull('users.deleted_at')
            ->where('role_permissions.permission_key', 'platform.'.$permission)
            ->when($except, fn ($q) => $q->where('platform_staff.user_id', '!=', $except))
            ->pluck('platform_staff.user_id')->unique()->values()->all();
    }

    /** Tidak ada staf lain yang berwenang → pengguna ini satu-satunya yang bisa memutuskan. */
    public static function solo(string $permission, int $userId): bool
    {
        return self::userIds($permission, $userId) === [];
    }
}
