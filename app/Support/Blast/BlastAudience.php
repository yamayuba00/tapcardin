<?php

namespace App\Support\Blast;

use App\Models\StaffAccount;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Penerima email blast menurut audiens — aturan sama dengan pengumuman (SendAnnouncement): pengguna yang tidak
 * diblokir/dihapus, anggota aktif workspace personal/perusahaan/paket. Audiens `test` = staf panel aktif (uji
 * internal). Alamat yang berhenti berlangganan selalu dikecualikan.
 */
final class BlastAudience
{
    /** @return Builder baris {user_id, email} (email huruf kecil) */
    public static function query(string $audience, ?int $planId): Builder
    {
        $notUnsubscribed = fn ($q, string $col) => $q->whereNotExists(fn ($s) => $s->from('email_unsubscribes')->whereColumn('email_unsubscribes.email', DB::raw("lower($col)")));

        if ($audience === 'test') {
            return StaffAccount::query()->where('status', 'active')
                ->selectRaw('NULL::bigint as user_id, lower(email) as email, id')
                ->tap(fn ($q) => $notUnsubscribed($q, 'staff_accounts.email'));
        }

        $tenants = match ($audience) {
            'personal' => Tenant::where('type', 'personal')->select('id'),
            'company' => Tenant::where('type', '!=', 'personal')->select('id'),
            'plan' => Tenant::where('plan_id', $planId ?? 0)->select('id'),
            default => null,
        };

        return User::query()->whereNull('blocked_at')->where('email', 'like', '%@%')
            ->when($tenants, fn ($q) => $q->whereIn('id', fn ($m) => $m->from('tenant_members')->select('user_id')->where('status', 'active')->whereIn('tenant_id', $tenants)))
            ->tap(fn ($q) => $notUnsubscribed($q, 'users.email'))
            ->selectRaw('id as user_id, lower(email) as email, id');
    }

    public static function count(string $audience, ?int $planId): int
    {
        return (int) DB::query()->fromSub(self::query($audience, $planId)->toBase()->reorder()->select(DB::raw('distinct lower(email) as email')), 'a')->count();
    }
}
