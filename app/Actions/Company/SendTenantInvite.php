<?php

namespace App\Actions\Company;

use App\Exceptions\ApiError;
use App\Mail\TenantInvite as TenantInviteMail;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantInvite;
use App\Models\User;
use App\Support\Notify;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/** Membuat atau mengirim ulang undangan. Token acak 64 karakter: hanya hash-nya yang disimpan; tautan dikirim lewat email. */
final class SendTenantInvite
{
    public const TTL_HOURS = 72;

    /** `$existing` = kirim ulang: token lama langsung tidak berlaku. */
    public function handle(Tenant $tenant, string $email, Role $role, ?User $by, ?TenantInvite $existing = null): TenantInvite
    {
        $token = Str::random(64);
        $attrs = [
            'email' => $email, 'role_id' => $role->id, 'token_hash' => TenantInvite::hashToken($token),
            'invited_by' => $by?->id, 'expires_at' => now()->addHours(self::TTL_HOURS), 'accepted_at' => null, 'revoked_at' => null,
        ];

        return DB::transaction(function () use ($tenant, $email, $role, $by, $existing, $attrs, $token) {
            $invite = $existing ? tap($existing)->update($attrs) : TenantInvite::create(['tenant_id' => $tenant->id, ...$attrs]);
            try {
                Mail::to($email)->send(new TenantInviteMail(
                    $tenant->name, $role->name, $by?->name, Notify::baseUrl($tenant).'/join/'.$token, $invite->expires_at,
                ));
            } catch (Throwable $e) {
                report($e); // pesan galat SMTP saja; tautan (token) tidak ikut dicatat
                throw new ApiError('invite_mail_failed', 'Email undangan belum bisa dikirim. Coba lagi sebentar.', 503);
            }

            return $invite;
        });
    }
}
