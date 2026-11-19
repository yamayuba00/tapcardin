<?php

namespace App\Support;

use App\Enums\MemberStatus;
use App\Models\TenantMember;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Daftar anggota aktif untuk pemilih (penugasan kartu & lead): dicari di server (`q` nama/email), dibatasi `limit`,
 * urut nama. `$scope` = id pengguna yang boleh dipilih (Manager: anggota timnya), null = semua anggota.
 */
final class MemberPicker
{
    /** @param  list<int>|null  $scope */
    public static function json(Request $request, ?array $scope): JsonResponse
    {
        $q = trim((string) $request->query('q'));
        $limit = min(max((int) $request->query('limit', 20), 1), 50);
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q).'%';

        $members = TenantMember::with('user:id,public_id,name,email')
            ->where('status', MemberStatus::Active->value)
            ->when($scope !== null, fn ($w) => $w->whereIn('user_id', $scope))
            ->when($q !== '', fn ($w) => $w->whereHas('user', fn ($u) => $u->where(fn ($x) => $x->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like))))
            ->orderBy(User::selectRaw('lower(name)')->whereColumn('users.id', 'tenant_members.user_id'))
            ->orderBy('tenant_members.id')
            ->limit($limit)->get();

        return response()->json(['data' => $members->map(fn (TenantMember $m) => ['user_id' => $m->user->public_id, 'name' => $m->user->name, 'email' => $m->user->email])]);
    }
}
