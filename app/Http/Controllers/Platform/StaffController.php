<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Auth\RegisterUser;
use App\Authz\PermissionResolver;
use App\Exceptions\ApiError;
use App\Models\PlatformStaff;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit;
use App\Support\Http\Table;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** PL-15 — undang staf, tetapkan peran platform, cabut akses. Pemilik Platform minimal 1. */
class StaffController extends PlatformController
{
    public function index(Request $request): JsonResponse
    {
        $this->can('staff.manage');
        $q = PlatformStaff::with(['user', 'role'])->join('users', 'users.id', '=', 'platform_staff.user_id')->select('platform_staff.*')
            ->when($request->query('status'), fn ($w, $s) => $w->where('platform_staff.status', $s));
        $sorts = ['name' => 'users.name', 'email' => 'users.email', 'status' => 'platform_staff.status', 'last_reverified_at' => 'platform_staff.last_reverified_at'];

        return Table::json(Table::paginate($q, $request, $sorts, 'name', ['users.name', 'users.email']), fn ($s) => self::row($s), $request, [
            'roles' => Role::where('scope', 'platform')->where('is_system', true)->orderBy('id')->get()
                ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'description' => $r->description]),
        ]);
    }

    private static function row(PlatformStaff $s): array
    {
        return [
            'id' => $s->id, 'name' => $s->user->name, 'email' => $s->user->email, 'role_id' => $s->role_id,
            'role' => $s->role->name, 'status' => $s->status, 'last_reverified_at' => self::dt($s->last_reverified_at),
            'has_password' => $s->user->password !== null,
        ];
    }

    /**
     * Respons aksi staf: hanya password sementara yang baru diterbitkan (sekali tampil);
     * tabel staf dimuat ulang sendiri lewat GET /api/staff.
     */
    private function listing(?array $issued = null): JsonResponse
    {
        return $this->ok(['issued' => $issued]);
    }

    public function store(Request $request, RegisterUser $register): JsonResponse
    {
        $this->can('staff.manage');
        $data = $request->validate([
            'email' => ['required', 'email'],
            'role_id' => ['required', Rule::exists('roles', 'id')->where('scope', 'platform')],
        ]);
        $user = User::whereRaw('lower(email) = ?', [strtolower($data['email'])])->first()
            ?? $register->handle($data['email']);
        $staff = PlatformStaff::updateOrCreate(['user_id' => $user->id], [
            'role_id' => $data['role_id'], 'status' => 'active', 'invited_by' => auth()->id(),
        ]);
        $temporary = $user->password ? null : $this->issueTemporaryPassword($user);
        PermissionResolver::bumpPlatform();
        Audit::platform('staff.invited', $staff, null, ['email' => $user->email, 'role_id' => (int) $data['role_id']]);

        return $this->listing(['email' => $user->email, 'temporary_password' => $temporary]);
    }

    /** Password sementara baru, ditampilkan sekali; staf menggantinya lewat menu Akun. */
    public function resetPassword(PlatformStaff $staff): JsonResponse
    {
        $this->can('staff.manage');
        $temporary = $this->issueTemporaryPassword($staff->user);
        Audit::platform('staff.password_reset', $staff);

        return $this->listing(['email' => $staff->user->email, 'temporary_password' => $temporary]);
    }

    private function issueTemporaryPassword(User $user): string
    {
        $password = Str::password(12, symbols: false);
        $user->forceFill(['password' => $password, 'password_changed_at' => null])->save();

        return $password;
    }

    public function update(Request $request, PlatformStaff $staff): JsonResponse
    {
        $this->can('staff.manage');
        $data = $request->validate([
            'role_id' => ['sometimes', Rule::exists('roles', 'id')->where('scope', 'platform')],
            'status' => ['sometimes', Rule::in(['active', 'revoked'])],
        ]);
        $ownerRole = Role::system('platform_owner')->id;
        $losingOwner = $staff->role_id === $ownerRole && $staff->status === 'active'
            && (($data['role_id'] ?? $ownerRole) != $ownerRole || ($data['status'] ?? 'active') === 'revoked');
        if ($losingOwner && PlatformStaff::where('role_id', $ownerRole)->where('status', 'active')->count() <= 1) {
            throw new ApiError('last_owner', 'Minimal harus ada satu Pemilik Platform aktif.');
        }
        $before = $staff->only(['role_id', 'status']);
        $staff->update($data);
        PermissionResolver::bumpPlatform();
        Audit::platform('staff.updated', $staff, $before, $staff->only(['role_id', 'status']));

        return $this->listing();
    }
}
