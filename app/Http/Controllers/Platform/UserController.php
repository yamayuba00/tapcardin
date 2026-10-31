<?php

namespace App\Http\Controllers\Platform;

use App\Exceptions\ApiError;
use App\Models\Card;
use App\Models\TenantMember;
use App\Models\User;
use App\Support\Audit;
use App\Support\Http\Table;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** PL-03 — cari pengguna, workspace yang diikuti, blokir, paksa keluar semua sesi. */
class UserController extends PlatformController
{
    public function index(Request $request): JsonResponse
    {
        $this->can('users.view');
        $users = Table::paginate(User::withTrashed(), $request, [
            'name' => 'name', 'email' => 'email', 'created_at' => 'created_at', 'last_login_at' => 'last_login_at',
        ], '-created_at', ['email', 'name', 'handle']);

        return Table::json($users, fn (User $u) => self::summary($u), $request);
    }

    /** GET /api/users/{id}/cards — kartu yang dipegang / ditugaskan ke pengguna (server-side). */
    public function cards(Request $request, User $user): JsonResponse
    {
        $this->can('users.view');
        $q = Card::acrossTenants()->with(['holder', 'batch'])
            ->where(fn ($w) => $w->where('holder_user_id', $user->id)->orWhere('assigned_user_id', $user->id))
            ->when($request->query('status'), fn ($w, $s) => $w->where('status', $s));

        return Table::json(Table::paginate($q, $request, CardController::SORTS, '-updated_at', ['card_token', 'short_code'], 10),
            fn (Card $c) => CardController::summary($c), $request);
    }

    public function show(User $user): JsonResponse
    {
        $this->can('users.view');

        return $this->ok(self::summary($user) + [
            'handle' => $user->handle,
            'sessions' => DB::table('sessions')->where('user_id', $user->id)->count(),
            'memberships' => TenantMember::acrossTenants()->with(['tenant', 'role'])->where('user_id', $user->id)->get()
                ->map(fn ($m) => ['tenant_id' => $m->tenant->public_id, 'name' => $m->tenant->name, 'type' => $m->tenant->type->value, 'role' => $m->role->name]),
        ]);
    }

    public function block(Request $request, User $user): JsonResponse
    {
        $this->can('users.manage');
        if ($user->id === auth()->id()) {
            throw new ApiError('self_block', 'Tidak bisa memblokir akun sendiri.');
        }
        $user->forceFill(['blocked_at' => now()])->save();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        Audit::platform('user.blocked', $user, null, ['reason' => $request->input('reason')]);

        return $this->show($user);
    }

    public function unblock(User $user): JsonResponse
    {
        $this->can('users.manage');
        $user->forceFill(['blocked_at' => null])->save();
        Audit::platform('user.unblocked', $user);

        return $this->show($user);
    }

    public function logoutAll(User $user): JsonResponse
    {
        $this->can('users.manage');
        $n = DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->forceFill(['remember_token' => null])->save();
        Audit::platform('user.sessions_revoked', $user, null, ['sessions' => $n]);

        return $this->ok(['revoked' => $n]);
    }

    public static function summary(User $u): array
    {
        return [
            'id' => $u->public_id,
            'name' => $u->name,
            'email' => $u->email,
            'status' => $u->trashed() ? 'deleted' : ($u->isBlocked() ? 'blocked' : 'active'),
            'last_login_at' => self::dt($u->last_login_at),
            'created_at' => self::dt($u->created_at),
        ];
    }
}
