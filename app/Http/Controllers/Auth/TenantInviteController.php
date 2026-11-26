<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\LoginUser;
use App\Actions\Company\AcceptTenantInvite;
use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantInvite;
use App\Support\HostTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aktivasi undangan karyawan di host perusahaan (subdomain / domain kustom): /join/{token} di SPA.
 * Tamu belum punya sesi, jadi workspace ditentukan dari host, bukan dari akun yang masuk.
 */
class TenantInviteController extends Controller
{
    private const INVALID = 'Undangan ini tidak berlaku lagi. Minta owner mengirim undangan baru.';

    /** GET /api/invites/{token} — nama workspace & peran saja; email tidak ditampilkan (harus diketik sendiri). */
    public function show(Request $request, string $token): JsonResponse
    {
        $tenant = $this->tenant($request);
        $invite = $this->open($tenant, $token) ?? throw new ApiError('invite_invalid', self::INVALID, 404);

        return response()->json(['data' => [
            'workspace' => HostTenant::brand($tenant),
            'role' => $invite->role()->value('name'),
            'expires_at' => $invite->expires_at->toIso8601String(),
        ]])->header('Cache-Control', 'no-store');
    }

    /** POST /api/invites/{token}/accept {email, name?, password, password_confirmation} */
    public function accept(Request $request, string $token, AcceptTenantInvite $accept, LoginUser $login): JsonResponse
    {
        $tenant = $this->tenant($request);
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:190'],
            'name' => ['nullable', 'string', 'max:120'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        ], [
            'email.email' => 'Tulis email yang valid.',
            'password.min' => 'Kata sandi minimal 8 karakter.',
            'password.max' => 'Kata sandi maksimal 72 karakter.',
            'password.confirmed' => 'Konfirmasi kata sandi tidak sama.',
        ]);

        $user = $accept->handle($tenant, $token, $data['email'], $data['password'], $data['name'] ?? null);
        $login->login($user, true);

        return response()->json(['data' => ['redirect' => '/dashboard']]);
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = HostTenant::resolve($request->getHost());
        abort_if($tenant === null || $tenant->isPersonal(), 404);

        return $tenant;
    }

    private function open(Tenant $tenant, string $token): ?TenantInvite
    {
        $invite = TenantInvite::acrossTenants()->where('tenant_id', $tenant->id)
            ->where('token_hash', TenantInvite::hashToken($token))->first();

        return $invite?->isOpen() ? $invite : null;
    }
}
