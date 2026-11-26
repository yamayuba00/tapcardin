<?php

namespace App\Actions\Company;

use App\Actions\Auth\RegisterUser;
use App\Authz\PermissionResolver;
use App\Enums\MemberStatus;
use App\Exceptions\ApiError;
use App\Models\Tenant;
use App\Models\TenantInvite;
use App\Models\TenantMember;
use App\Models\User;
use App\Support\Audit;
use App\Support\Webhooks\WebhookPayload;
use App\Support\Webhooks\Webhooks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Aktivasi undangan: token harus berlaku (belum dipakai, belum dicabut, belum kedaluwarsa) dan email harus sama.
 * Akun baru dibuat (atau akun yang sudah ada ditautkan), lalu menjadi anggota aktif dengan peran undangan.
 */
final class AcceptTenantInvite
{
    private const INVALID = 'Undangan ini tidak berlaku lagi. Minta owner mengirim undangan baru.';

    public function __construct(private readonly RegisterUser $register) {}

    public function handle(Tenant $tenant, string $token, string $email, string $password, ?string $name): User
    {
        return DB::transaction(function () use ($tenant, $token, $email, $password, $name) {
            $invite = TenantInvite::acrossTenants()->where('tenant_id', $tenant->id)
                ->where('token_hash', TenantInvite::hashToken($token))->lockForUpdate()->first();
            if (! $invite || ! $invite->isOpen()) {
                throw new ApiError('invite_invalid', self::INVALID, 422);
            }
            if (strtolower(trim($email)) !== strtolower($invite->email)) {
                throw new ApiError('email_mismatch', 'Email tidak sama dengan email undangan.', 422, ['fields' => ['email' => ['Email tidak sama dengan email undangan.']]]);
            }

            $user = User::whereRaw('lower(email) = ?', [strtolower($invite->email)])->first();
            if ($user?->isBlocked()) {
                throw new ApiError('invite_invalid', self::INVALID, 422);
            }
            $member = $user ? TenantMember::acrossTenants()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->first() : null;
            if ($member?->status === MemberStatus::Active) {
                throw new ApiError('member_exists', 'Anda sudah menjadi anggota workspace ini. Silakan masuk.', 422);
            }

            if ($user) {
                // akun sudah punya kata sandi: tidak ditimpa lewat undangan, harus cocok dengan kata sandi yang ada
                if ($user->password !== null && ! Hash::check($password, $user->password)) {
                    throw new ApiError('password_mismatch', 'Kata sandi tidak cocok dengan akun ini. Pakai kata sandi akun Anda yang sekarang.', 422, ['fields' => ['password' => ['Kata sandi tidak cocok dengan akun ini.']]]);
                }
                // akun tanpa kata sandi (mis. baru masuk dengan Google): kata sandi baru dipasang
                if ($user->password === null) {
                    $user->forceFill(['password' => $password, 'password_changed_at' => now()])->save();
                }
            } else {
                $user = $this->register->handle($invite->email, $name, null, Hash::make($password));
            }

            $attrs = ['role_id' => $invite->role_id, 'status' => MemberStatus::Active->value, 'removed_at' => null, 'removed_by' => null];
            $member ? $member->update($attrs) : TenantMember::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, ...$attrs]);

            $invite->forceFill(['accepted_at' => now()])->save();
            $role = $invite->role()->first();
            PermissionResolver::bump($tenant->id);
            Audit::tenant($tenant->id, 'member.invite_accepted', $user, null, ['role' => $role->key]);
            Webhooks::emit($tenant->id, 'member.added', fn () => ['user' => WebhookPayload::user($user), 'role' => $role->key, 'status' => 'active']);

            return $user;
        });
    }
}
