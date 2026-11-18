<?php

namespace App\Authz;

use App\Models\StaffAccount;

/**
 * Siapa saja staf aktif yang berwenang untuk suatu izin platform. Dipakai aturan "dua orang":
 * pengaju dan penyetuju harus berbeda. Hanya bila TIDAK ADA staf lain yang berwenang (mis. baru satu Pemilik),
 * pengaju boleh menyetujui sendiri — tercatat sebagai `self_approved` agar terlihat di audit.
 */
final class Approvers
{
    /** @return list<int> id staf aktif yang punya izin; opsional kecualikan satu staf. */
    public static function staffIds(string $permission, ?int $except = null): array
    {
        return StaffAccount::query()
            ->join('role_permissions', 'role_permissions.role_id', '=', 'staff_accounts.role_id')
            ->where('staff_accounts.status', 'active')
            ->where('role_permissions.permission_key', 'platform.'.$permission)
            ->when($except, fn ($q) => $q->where('staff_accounts.id', '!=', $except))
            ->pluck('staff_accounts.id')->unique()->values()->all();
    }

    /** Tidak ada staf lain yang berwenang → staf ini satu-satunya yang bisa memutuskan. */
    public static function solo(string $permission, int $staffId): bool
    {
        // dipanggil per baris di daftar refund/persetujuan → satu query per (izin, staf) per request
        return once(fn () => self::staffIds($permission, $staffId) === []);
    }
}
