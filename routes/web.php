<?php

use App\Http\Controllers\Marketing\ShowcaseController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\SpaController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| TRD §4.6 — rute dipilih berdasarkan host. Laravel hanya menyediakan endpoint (JSON, unduhan,
| redirect); semua halaman adalah SPA Vue yang disajikan oleh SpaController.
|   tap.{base}    resolver permanen (tercetak di chip & QR)
|   panel.{base}  panel pemilik & produksi (sesi terpisah; subdomain diatur tapcard.panel_subdomain)
|   app.{base}    workspace Personal + login pusat
|   {slug}.{base} workspace Perusahaan
*/
$base = config('tapcard.base_domain');
$spa = fn () => Route::get('/{path?}', SpaController::class)->where('path', '^(?!api(/|$)).*');

Route::domain('tap.'.$base)->group(function () use ($spa) {
    require base_path('routes/public.php');
    $spa();
});

$panel = config('tapcard.panel_subdomain');

// alamat lama admin.{base} → panel.{base} (bookmark staf tetap jalan)
if ($panel !== 'admin') {
    Route::domain('admin.'.$base)->group(fn () => Route::get('/{path?}', fn (Request $r, ?string $path = null) => redirect()->away(
        $r->getScheme().'://'.$panel.'.'.$base.($r->getPort() && ! in_array($r->getPort(), [80, 443], true) ? ':'.$r->getPort() : '').'/'.ltrim((string) $path, '/').($r->getQueryString() ? '?'.$r->getQueryString() : ''), 301,
    ))->where('path', '.*'));
}

Route::domain($panel.'.'.$base)->name('admin.')->group(function () use ($spa) {
    require base_path('routes/platform.php');
    $spa()->name('spa');
});

Route::domain('{workspace}.'.$base)
    ->where(['workspace' => '(?!(?:tap|admin|panel|www|ws)\.)[a-z0-9][a-z0-9-]{0,28}[a-z0-9]'])
    ->middleware('workspace')
    ->group(function () use ($spa) {
        require base_path('routes/workspace.php');
        $spa()->name('spa');
    });

Route::domain($base)->group(function () use ($spa) {
    Route::get('/api/showcase', ShowcaseController::class)->middleware('throttle:60,1')->name('showcase');
    // notifikasi gateway pembayaran (CSRF dikecualikan untuk webhooks/*)
    Route::post('/webhooks/payment', PaymentWebhookController::class)->middleware('throttle:120,1')->name('webhooks.payment');
    $spa()->name('marketing');
});

// Domain kustom workspace (tenant_domains aktif) — rute workspace yang sama, nama berawalan `custom.`.
// Didaftarkan terakhir agar host lain di atas selalu menang; host tak dikenal → 404 dari ResolveWorkspace.
Route::middleware('workspace')->name('custom.')->group(function () use ($spa) {
    require base_path('routes/workspace.php');
    $spa()->name('spa');
});
