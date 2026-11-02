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

        // PWA hanya untuk aplikasi (app., subdomain workspace, domain kustom) — bukan resolver tap., panel, atau landing.
        $host = request()->getHost();
        $pwa = $host !== $base && ! preg_match('/^(tap|www|admin|'.preg_quote(config('tapcard.panel_subdomain'), '/').')\./', $host);

        return response()->view('app', [
            'meta' => $meta,
            'pwa' => $pwa,
            'config' => [
                'baseDomain' => $base,
                'resolverUrl' => config('tapcard.resolver_url'),
                'appUrl' => "$scheme://app.$base$suffix",
                'adminUrl' => "$scheme://".config('tapcard.panel_subdomain').".$base$suffix",
                'marketingUrl' => "$scheme://$base$suffix",
                'googleEnabled' => (bool) config('services.google.client_id'),
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
