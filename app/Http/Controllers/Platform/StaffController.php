<?php

namespace App\Http\Controllers\Platform;

use App\Authz\PermissionResolver;
use App\Exceptions\ApiError;
use App\Models\Role;
use App\Models\StaffAccount;
use App\Support\Audit;
use App\Support\Http\Table;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** PL-15 — undang staf, tetapkan peran platform, cabut akses. Pemilik Platform minimal 1. Akun staf terpisah dari pengguna app. */
class StaffController extends PlatformController
{
    public function index(Request $request): JsonResponse
    {
        $this->can('staff.manage');
        $q = StaffAccount::with('role')->when($request->query('status'), fn ($w, $s) => $w->where('status', $s));
        $sorts = ['name' => 'name', 'email' => 'email', 'status' => 'status', 'last_reverified_at' => 'last_reverified_at'];

        return Table::json(Table::paginate($q, $request, $sorts, 'name', ['name', 'email']), fn ($s) => self::row($s), $request, [
            'roles' => Role::where('scope', 'platform')->where('is_system', true)->orderBy('id')->get()
                ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'description' => $r->description]),
        ]);
    }

    private static function row(StaffAccount $s): array
    {
        return [
            'id' => $s->public_id, 'name' => $s->name, 'email' => $s->email, 'role_id' => $s->role_id,
            'role' => $s->role->name, 'status' => $s->status, 'last_reverified_at' => self::dt($s->last_reverified_at),
            'has_password' => $s->password !== null,
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

    public function store(Request $request): JsonResponse
    {
        $this->can('staff.manage');
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'name' => ['nullable', 'string', 'max:120'],
            'role_id' => ['required', Rule::exists('roles', 'id')->where('scope', 'platform')],
        ]);
        $email = strtolower(trim($data['email']));
        $staff = StaffAccount::whereRaw('lower(email) = ?', [$email])->first() ?? new StaffAccount([
            'email' => $email, 'name' => $data['name'] ?? ucwords(str_replace(['.', '_', '-'], ' ', strstr($email, '@', true))),
        ]);
        $staff->fill(['role_id' => $data['role_id'], 'status' => 'active'])->forceFill(['invited_by' => $staff->invited_by ?? auth('staff')->id()])->save();
        $temporary = $staff->password ? null : $this->issueTemporaryPassword($staff);
        PermissionResolver::bumpPlatform();
        Audit::platform('staff.invited', $staff, null, ['email' => $staff->email, 'role_id' => (int) $data['role_id']]);

        return $this->listing(['email' => $staff->email, 'temporary_password' => $temporary]);
    }

    /** Password sementara baru, ditampilkan sekali; staf menggantinya lewat menu Akun. */
    public function resetPassword(StaffAccount $staff): JsonResponse
    {
        $this->can('staff.manage');
        $temporary = $this->issueTemporaryPassword($staff);
        Audit::platform('staff.password_reset', $staff);

        return $this->listing(['email' => $staff->email, 'temporary_password' => $temporary]);
    }

    private function issueTemporaryPassword(StaffAccount $staff): string
    {
        $password = Str::password(12, symbols: false);
        $staff->forceFill(['password' => $password, 'password_changed_at' => null])->save();

        return $password;
    }

    public function update(Request $request, StaffAccount $staff): JsonResponse
    {
        $this->can('staff.manage');
        $data = $request->validate([
            'role_id' => ['sometimes', Rule::exists('roles', 'id')->where('scope', 'platform')],
            'status' => ['sometimes', Rule::in(['active', 'revoked'])],
        ]);
        $ownerRole = Role::system('platform_owner')->id;
        $losingOwner = $staff->role_id === $ownerRole && $staff->status === 'active'
            && (($data['role_id'] ?? $ownerRole) != $ownerRole || ($data['status'] ?? 'active') === 'revoked');
        if ($losingOwner && StaffAccount::where('role_id', $ownerRole)->where('status', 'active')->count() <= 1) {
            throw new ApiError('last_owner', 'Minimal harus ada satu Pemilik Platform aktif.');
        }
        $before = $staff->only(['role_id', 'status']);
        $staff->update($data);
        PermissionResolver::bumpPlatform();
        Audit::platform('staff.updated', $staff, $before, $staff->only(['role_id', 'status']));

        return $this->listing();
    }
}
