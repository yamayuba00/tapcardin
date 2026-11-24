<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Semua /api membutuhkan header X-Tap-Key, kecuali endpoint tap (NFC/QR tidak bisa membawa header) dan
 * aliran SSE notifikasi (EventSource tidak bisa membawa header; tetap dijaga sesi).
 */
class RequireTapApiKey
{
    private const OPEN = ['api/c/*', 'api/shortcode', 'api/handle/*', 'api/stream'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/*') || $request->is(self::OPEN)) {
            return $next($request);
        }

        $key = (string) config('services.tap_api.key');
        if ($key === '') {
            if (app()->environment(['local', 'testing'])) {
                return $next($request);
            }

            return response()->json(['error' => ['code' => 'api_key_not_configured', 'message' => 'Layanan belum siap.']], 503);
        }

        if (! hash_equals($key, (string) $request->header('X-Tap-Key', ''))) {
            return response()->json(['error' => ['code' => 'api_key_required', 'message' => 'Kunci API diperlukan.']], 403);
        }

        return $next($request);
    }
}
