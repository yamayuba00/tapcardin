<?php

namespace App\Http\Middleware;

use App\Support\Bio\BioBlocks;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** TRD §11 — CSP dengan nonce, nosniff, referrer policy, permissions policy, HSTS. */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Str::random(24);
        Vite::useCspNonce($nonce);
        app()->instance('csp-nonce', $nonce);

        $response = $next($request);

        // SPA: Permissions-Policy hanya dibaca saat dokumen pertama dimuat, jadi harus berlaku untuk seluruh
        // aplikasi yang punya halaman pemindai (Mode Reader di app/{slug}, encode & QC di admin) — bukan per path.
        // Halaman publik (tap. & situs marketing) tetap tanpa kamera/NFC.
        $base = config('tapcard.base_domain');
        $host = $request->getHost();
        $isScanPage = $host !== $base && ! str_starts_with($host, 'tap.');
        $dev = app()->isLocal() ? ' http://localhost:5173 http://[::1]:5173 ws://localhost:5173 ws://[::1]:5173' : '';
        // unggahan di S3: gambar dimuat dari host bucket, dan diambil (fetch) saat ekspor PNG desain
        $media = config('filesystems.disks.public.driver') === 's3' && ($u = parse_url((string) config('filesystems.disks.public.url'))) && isset($u['host'])
            ? ' '.$u['scheme'].'://'.$u['host'] : '';
        // WebSocket Reverb (ws:// lokal, wss:// produksi) untuk notifikasi realtime
        $rt = config('broadcasting.default') === 'reverb' && ($h = config('broadcasting.connections.reverb.options.host'))
            ? ' '.(config('broadcasting.connections.reverb.options.scheme') === 'https' ? 'wss' : 'ws').'://'.$h.':'.config('broadcasting.connections.reverb.options.port')
            : '';
        $csp = implode('; ', [
            "default-src 'self'",
            "img-src 'self' data: blob: ".$this->formTargets().$media,
            "script-src 'self' 'nonce-$nonce'$dev",
            "style-src 'self' 'nonce-$nonce'$dev",
            "font-src 'self'",
            "connect-src 'self'$dev$media$rt",
            "frame-src 'self' ".implode(' ', BioBlocks::EMBED_ORIGINS), // embed video bio link
            "frame-ancestors 'self'",
            "base-uri 'none'",
            "form-action 'self' ".$this->formTargets(),
        ]);

        $response->headers->set('Content-Security-Policy', $csp);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', $isScanPage ? 'camera=(self), nfc=(self)' : 'camera=(), nfc=(), geolocation=()');
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    /** Form di host tap. boleh mengirim ke app. (mis. aktivasi), dan sebaliknya. */
    private function formTargets(): string
    {
        $base = config('tapcard.base_domain');

        return "*.$base:*";
    }
}
