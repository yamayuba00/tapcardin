<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Internal\CaddyAskController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Jalan sebelum routing: `/internal/caddy-ask` tidak boleh tertangkap rute host mana pun. */
class CaddyAskMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->path() === 'internal/caddy-ask') {
            return app(CaddyAskController::class)($request);
        }

        return $next($request);
    }
}
