<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * TRD §5.4 — panel.domain.com memakai cookie & tabel sesi terpisah (domain admin saja) dan guard `staff`
 * (tabel staff_accounts) agar akun & sesi pelanggan dan staf tidak tercampur. Harus berjalan sebelum StartSession.
 */
class UseAdminSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->getHost() === config('tapcard.panel_subdomain').'.'.config('tapcard.base_domain')) {
            config([
                'session.cookie' => config('tapcard.admin_session_cookie'),
                'session.table' => 'staff_sessions',
                'session.domain' => null,
                'session.lifetime' => 12 * 60,
                'session.expire_on_close' => false,
            ]);
            Auth::shouldUse('staff');
        } else {
            Auth::shouldUse('web');
        }

        return $next($request);
    }
}
