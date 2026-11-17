<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiError;
use App\Models\StaffAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** TRD §5.4 — staf aktif, verifikasi ulang tiap 12 jam, IP allowlist opsional. */
class EnsurePlatformStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $staff = $request->user('staff');
        if (! $staff instanceof StaffAccount || ! $staff->isActive()) {
            throw new ApiError('not_staff', 'Akun ini bukan staf aktif TapCard.', 403);
        }

        if ($staff->ip_allowlist && ! in_array($request->ip(), $staff->ip_allowlist, true)) {
            throw new ApiError('ip_not_allowed', 'Akses panel pemilik tidak diizinkan dari jaringan ini.', 403);
        }

        $hours = config('tapcard.admin_reverify_hours');
        if (! $staff->last_reverified_at || $staff->last_reverified_at->lt(now()->subHours($hours))) {
            auth('staff')->logout();
            $request->session()->invalidate();
            throw new ApiError('reverify_required', "Sesi panel pemilik berakhir setelah $hours jam. Masuk lagi untuk verifikasi.", 401);
        }

        return $next($request);
    }
}
