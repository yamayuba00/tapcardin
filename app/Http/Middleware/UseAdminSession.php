<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TRD §5.4 — panel.domain.com memakai cookie sesi terpisah (domain admin saja) agar
 * sesi pelanggan dan staf tidak tercampur. Harus berjalan sebelum StartSession.
 */
class UseAdminSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->getHost() === config('tapcard.panel_subdomain').'.'.config('tapcard.base_domain')) {
            config([
                'session.cookie' => config('tapcard.admin_session_cookie'),
                'session.domain' => null,
                'session.lifetime' => 12 * 60,
                'session.expire_on_close' => false,
            ]);
        }

        return $next($request);
    }
}
