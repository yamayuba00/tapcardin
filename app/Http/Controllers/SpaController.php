<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Auth\AuthController;
use Database\Seeders\DummyAccountSeeder;
use Illuminate\Http\Response;

/**
 * Menyajikan shell HTML SPA Vue untuk semua rute non-API. Shell tidak berisi UI;
 * seluruh tampilan dirender Vue (resources/js) dan data diambil dari /api.
 * Halaman yang sensitif kecepatan (profil publik /c/{token}) boleh menyisipkan data awal + meta.
 */
class SpaController extends Controller
{
    public function __invoke(): Response
    {
        return $this->render();
    }

    /**
     * @param  array  $extra  ditambahkan ke config SPA (mis. `initial` = data awal halaman)
     * @param  array|null  $meta  {title, description, image, theme, lang} untuk <head>
     */
    public function render(array $extra = [], ?array $meta = null, int $status = 200): Response
    {
        $base = config('tapcard.base_domain');
        $port = request()->getPort();
        $suffix = $port && ! in_array($port, [80, 443], true) ? ':'.$port : '';
        $scheme = request()->getScheme();

        // PWA untuk aplikasi (app., subdomain workspace, domain kustom) dan panel (manifest & ikon sendiri) —
        // bukan resolver tap. atau landing. Nilai = [manifest, nama di layar utama, ikon Apple] atau null.
        $host = request()->getHost();
        $panel = str_starts_with($host, config('tapcard.panel_subdomain').'.');
        $pwa = match (true) {
            $panel => ['/manifest-panel.webmanifest', 'TC Panel', '/icons/panel-apple-touch-icon.png'],
            $host === $base || (bool) preg_match('/^(tap|www|admin)\./', $host) => null,
            default => ['/manifest.webmanifest', 'TapCard', '/icons/apple-touch-icon.png'],
        };

        return response()->view('app', [
            'meta' => $meta,
            'pwa' => $pwa,
            'favicon' => $panel ? '/favicon-admin.svg' : '/favicon.svg',
            'config' => [
                'baseDomain' => $base,
                'resolverUrl' => config('tapcard.resolver_url'),
                'appUrl' => "$scheme://app.$base$suffix",
                'adminUrl' => "$scheme://".config('tapcard.panel_subdomain').".$base$suffix",
                'marketingUrl' => "$scheme://$base$suffix",
                'googleEnabled' => (bool) config('services.google.client_id'),
                // Realtime (Reverb): alamat publik WebSocket untuk Echo; null → SSE/polling seperti sebelumnya
                'realtime' => config('broadcasting.default') === 'reverb' && config('broadcasting.connections.reverb.key') ? [
                    'key' => config('broadcasting.connections.reverb.key'),
                    'host' => config('broadcasting.connections.reverb.options.host'),
                    'port' => (int) config('broadcasting.connections.reverb.options.port'),
                    'tls' => config('broadcasting.connections.reverb.options.scheme') === 'https',
                ] : null,
                // Hanya terisi di APP_ENV=local: akun dummy satu klik (app. tanpa Google; panel pakai password ini).
                'devLogin' => AuthController::devLogin() ? [
                    'password' => DummyAccountSeeder::DEV_PASSWORD,
                    'accounts' => collect(DummyAccountSeeder::ACCOUNTS)
                        ->map(fn ($v, $email) => ['email' => $email, 'name' => $v[0], 'staff' => $v[1] !== null])->values(),
                ] : null,
            ] + $extra,
        ], $status)->header('Cache-Control', 'no-cache, private');
    }
}
