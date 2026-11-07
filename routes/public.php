<?php

use App\Http\Controllers\Public\CardResolverController;
use App\Http\Controllers\Public\PublicCardController;
use App\Http\Controllers\Public\PublicFormController;
use Illuminate\Support\Facades\Route;

/*
| tap.{base} — BSD §8.2. URL `/c/{token}` di chip disajikan SPA, yang memanggil `GET /api/c/{token}`
| (di sinilah tap dicatat). Unduhan vCard, redirect tautan, dan SVG QR tetap endpoint server.
*/
Route::middleware('throttle:tap')->where(['token' => '[0-9A-Za-z]{10}'])->group(function () {
    // URL yang tercetak di chip & QR: shell SPA + data profil disisipkan (satu request, tap dicatat di sini)
    Route::get('/c/{token}', [CardResolverController::class, 'shell'])->name('card.shell');
    // navigasi di dalam SPA (mis. dari /k)
    Route::get('/api/c/{token}', CardResolverController::class)->name('card.resolve');
    Route::get('/q/{qr}', [CardResolverController::class, 'dynamic'])->where('qr', '[0-9A-Za-z]{24}')->name('card.dynamic');
    // QR kartu digital yang berganti berkala (paket Free)
    Route::get('/s/{code}', [CardResolverController::class, 'shared'])->where('code', '[0-9A-Za-z]{12}')->name('card.shared');
});

Route::controller(PublicCardController::class)->where(['token' => '[0-9A-Za-z]{10}'])->group(function () {
    Route::post('/api/c/{token}/connect', 'connect')->middleware('throttle:connect')->name('card.connect');
    Route::post('/api/c/{token}/forms/{block}', [PublicFormController::class, 'submit'])->where('block', '[0-9A-Z]{26}')->middleware('throttle:bio-form')->name('card.form');
    Route::post('/api/shortcode', 'shortCode')->middleware('throttle:shortcode')->name('shortcode');
    Route::get('/@{handle}', 'handleRedirect')->where('handle', '[a-z0-9_.]{3,30}')->middleware('throttle:tap')->name('handle.redirect');
    Route::get('/api/handle/{handle}', 'handle')->where('handle', '[a-z0-9_.]{3,30}')->name('handle');

    Route::get('/c/{token}/vcard', 'vcard')->middleware('throttle:vcard')->name('card.vcard');
    Route::get('/c/{token}/l/{field}', 'link')->name('card.link');
    Route::get('/c/{token}/qr.svg', 'qr')->name('card.qr');
});
