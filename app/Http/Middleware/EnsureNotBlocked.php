<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureNotBlocked
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isBlocked()) {
            auth()->logout();
            $request->session()->invalidate();
            abort(403, 'Akun ini diblokir. Hubungi support TapCard.');
        }

        return $next($request);
    }
}
