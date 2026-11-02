<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Workspace\ActivationController;
use App\Http\Controllers\Workspace\AddonController;
use App\Http\Controllers\Workspace\AnalyticsController;
use App\Http\Controllers\Workspace\BillingController;
use App\Http\Controllers\Workspace\BrandKitController;
use App\Http\Controllers\Workspace\CardController;
use App\Http\Controllers\Workspace\CardLoginKeyController;
use App\Http\Controllers\Workspace\ConnectionController;
use App\Http\Controllers\Workspace\DashboardController;
use App\Http\Controllers\Workspace\DesignController;
use App\Http\Controllers\Workspace\EventController;
use App\Http\Controllers\Workspace\ImportController;
use App\Http\Controllers\Workspace\LeadDashboardController;
use App\Http\Controllers\Workspace\MeController;
use App\Http\Controllers\Workspace\NotificationController;
use App\Http\Controllers\Workspace\OrderController;
use App\Http\Controllers\Workspace\ProfileController;
use App\Http\Controllers\Workspace\ReminderController;
use App\Http\Controllers\Workspace\ScanController;
use App\Http\Controllers\Workspace\StreamController;
use Illuminate\Support\Facades\Route;

/*
| app.{base} & {slug}.{base} — BSD §8.3–§8.4. Endpoint sama; izin & paket yang membedakan.
*/

// ── Autentikasi ────────────────────────────────────────────
// Pengguna masuk dengan Google atau kartu NFC + PIN — tanpa email + password (panel staf punya login sendiri).
Route::controller(AuthController::class)->prefix('api/auth')->group(function () {
    Route::get('/altcha', 'altcha')->middleware('throttle:60,1')->name('auth.altcha');
    Route::post('/card', 'card')->middleware('throttle:10,1')->name('auth.card');
    Route::post('/dev', 'dev')->middleware('throttle:30,1')->name('auth.dev');
    Route::post('/logout', 'logout')->name('logout');
});
Route::get('/auth/google/redirect', [GoogleController::class, 'redirect'])->name('google.redirect');
Route::get('/auth/google/callback', [GoogleController::class, 'callback'])->name('google.callback');

Route::middleware('auth')->prefix('api')->name('api.')->group(function () {
    Route::get('/me', [MeController::class, 'show'])->name('me');
    Route::patch('/me', [MeController::class, 'update'])->name('me.update');
    Route::get('/me/settings', [MeController::class, 'settings'])->name('me.settings');
    Route::post('/me/onboarding', [MeController::class, 'onboarding'])->name('me.onboarding');
    Route::get('/me/permissions', [MeController::class, 'permissions'])->name('me.permissions');
    Route::get('/me/export', [MeController::class, 'export'])->name('me.export');
    Route::delete('/me', [MeController::class, 'destroy'])->name('me.destroy');

    Route::get('/dashboard', [DashboardController::class, 'summary'])->name('dashboard');

    Route::post('/cards/digital', [CardController::class, 'createDigital'])->middleware('throttle:10,1')->name('cards.digital');
    Route::post('/cards/activate', [ActivationController::class, 'activate'])->middleware('throttle:activate')->name('cards.activate');
    Route::post('/cards/claim', [ActivationController::class, 'claim'])->middleware('throttle:activate')->name('cards.claim');
    Route::post('/cards/{token}/replacement', [CardController::class, 'requestReplacement'])->name('cards.replacement');
    Route::post('/cards/{token}/dynamic-qr', [CardController::class, 'dynamicQr'])->middleware('throttle:30,1')->name('cards.dynamic-qr');
    Route::get('/cards/{token}/activation', [ActivationController::class, 'info'])->name('cards.activation');
    Route::get('/cards', [CardController::class, 'list'])->name('cards.list');
    Route::post('/cards/{token}/shared', [CardController::class, 'shared'])->middleware('throttle:60,1')->name('cards.shared');
    Route::put('/cards/{token}/login-key', [CardLoginKeyController::class, 'update'])->middleware('throttle:10,1')->name('cards.login-key');
    Route::delete('/cards/{token}/login-key', [CardLoginKeyController::class, 'destroy'])->name('cards.login-key.destroy');
    Route::get('/cards/{token}/visual', [CardController::class, 'visual'])->name('cards.visual');
    Route::put('/cards/{token}/design', [CardController::class, 'design'])->name('cards.design');
    Route::get('/cards/{token}', [CardController::class, 'detail'])->name('cards.detail');
    Route::patch('/cards/{token}', [CardController::class, 'update'])->name('cards.update');

    Route::get('/bio/themes', [ProfileController::class, 'themes'])->name('bio.themes');
    Route::get('/profiles', [ProfileController::class, 'list'])->name('profiles.list');
    Route::post('/profiles', [ProfileController::class, 'store'])->name('profiles.store');
    Route::get('/profiles/{profile}', [ProfileController::class, 'detail'])->name('profiles.detail');
    Route::get('/profiles/{profile}/preview', [ProfileController::class, 'preview'])->name('profiles.preview');
    Route::patch('/profiles/{profile}', [ProfileController::class, 'update'])->name('profiles.update');
    Route::delete('/profiles/{profile}', [ProfileController::class, 'destroy'])->name('profiles.destroy');
    Route::put('/profiles/{profile}/fields', [ProfileController::class, 'fields'])->name('profiles.fields');
    Route::post('/profiles/{profile}/avatar', [ProfileController::class, 'avatar'])->name('profiles.avatar');
    Route::post('/profiles/{profile}/field-image', [ProfileController::class, 'fieldImage'])->middleware('throttle:30,1')->name('profiles.field-image');
    Route::get('/contact-types', [ProfileController::class, 'contactTypes'])->name('contact-types');

    Route::get('/leads', [ConnectionController::class, 'list'])->name('leads.list');
    Route::post('/leads', [ConnectionController::class, 'store'])->name('leads.store');
    Route::get('/leads/export.csv', [ConnectionController::class, 'export'])->name('leads.export');
    Route::get('/leads/tags', [ConnectionController::class, 'tags'])->name('leads.tags');
    // rute statis /leads/* didaftarkan sebelum /leads/{connection}
    Route::get('/leads/dashboard', [LeadDashboardController::class, 'show'])->name('leads.dashboard');
    Route::put('/leads/dashboard/layout', [LeadDashboardController::class, 'saveLayout'])->name('leads.dashboard.layout');
    Route::get('/leads/imports', [ImportController::class, 'index'])->name('imports.index');
    Route::post('/leads/imports', [ImportController::class, 'upload'])->middleware('throttle:20,60')->name('imports.upload');
    Route::get('/leads/imports/{import}', [ImportController::class, 'show'])->name('imports.show');
    Route::post('/leads/imports/{import}/start', [ImportController::class, 'start'])->name('imports.start');
    Route::delete('/leads/imports/{import}', [ImportController::class, 'destroy'])->name('imports.destroy');
    Route::get('/leads/{connection}', [ConnectionController::class, 'detail'])->name('leads.detail');
    Route::patch('/leads/{connection}', [ConnectionController::class, 'update'])->name('leads.update');
    Route::delete('/leads/{connection}', [ConnectionController::class, 'destroy'])->name('leads.destroy');
    Route::post('/leads/{connection}/stage', [ConnectionController::class, 'stage'])->name('leads.stage');
    Route::post('/leads/{connection}/activities', [ConnectionController::class, 'activity'])->name('leads.activity');
    Route::post('/leads/{connection}/reminders', [ReminderController::class, 'store'])->name('leads.reminders');
    Route::get('/reminders', [ReminderController::class, 'index'])->name('reminders.index');
    Route::patch('/reminders/{reminder}', [ReminderController::class, 'update'])->name('reminders.update');
    Route::delete('/reminders/{reminder}', [ReminderController::class, 'destroy'])->name('reminders.destroy');

    Route::post('/scans', [ScanController::class, 'store'])->middleware('throttle:60,1')->name('scans.store');
    Route::get('/scans/inbound', [ScanController::class, 'inbound'])->name('scans.inbound');

    Route::get('/events', [EventController::class, 'index'])->name('events.index');
    Route::post('/events', [EventController::class, 'store'])->name('events.store');
    Route::delete('/events/active', [EventController::class, 'deactivate'])->name('events.deactivate');
    Route::get('/events/{event}', [EventController::class, 'show'])->name('events.show');
    Route::patch('/events/{event}', [EventController::class, 'update'])->name('events.update');
    Route::delete('/events/{event}', [EventController::class, 'destroy'])->name('events.destroy');
    Route::post('/events/{event}/activate', [EventController::class, 'activate'])->name('events.activate');
    Route::get('/pipeline/stages', [ConnectionController::class, 'stages'])->name('stages');

    Route::get('/analytics/cards', [AnalyticsController::class, 'cards'])->name('analytics.cards');

    // Design Studio (PRD §10.3)
    Route::get('/studio/templates', [DesignController::class, 'templates'])->name('studio.templates');
    Route::get('/designs', [DesignController::class, 'index'])->name('designs.list');
    Route::post('/designs', [DesignController::class, 'store'])->name('designs.store');
    Route::get('/designs/{design}', [DesignController::class, 'show'])->name('designs.show');
    Route::patch('/designs/{design}', [DesignController::class, 'update'])->name('designs.update');
    Route::delete('/designs/{design}', [DesignController::class, 'destroy'])->name('designs.destroy');
    Route::post('/designs/{design}/validate', [DesignController::class, 'validateDesign'])->name('designs.validate');
    Route::post('/designs/{design}/assets', [DesignController::class, 'asset'])->name('designs.asset');
    Route::post('/designs/{design}/lanyard', [DesignController::class, 'createPairedLanyard'])->name('designs.lanyard');
    Route::post('/designs/{design}/template', [DesignController::class, 'saveAsTemplate'])->name('designs.template');
    Route::get('/brand-kits', [BrandKitController::class, 'index'])->name('kits.index');
    Route::post('/brand-kits', [BrandKitController::class, 'store'])->name('kits.store');
    Route::patch('/brand-kits/{kit}', [BrandKitController::class, 'update'])->name('kits.update');
    Route::post('/brand-kits/{kit}/logo', [BrandKitController::class, 'logo'])->name('kits.logo');
    Route::delete('/brand-kits/{kit}', [BrandKitController::class, 'destroy'])->name('kits.destroy');
    Route::get('/lanyards/quote', [BrandKitController::class, 'quote'])->name('lanyards.quote');

    Route::get('/billing', [BillingController::class, 'show'])->name('billing.show');
    Route::post('/billing/checkout', [BillingController::class, 'checkout'])->middleware('throttle:20,1')->name('billing.checkout');
    Route::get('/orders/catalog', [OrderController::class, 'catalog'])->name('orders.catalog');
    Route::get('/orders', [OrderController::class, 'index'])->name('orders');
    Route::post('/orders', [OrderController::class, 'store'])->middleware('throttle:10,1')->name('orders.store');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('/orders/{order}/pay', [OrderController::class, 'pay'])->middleware('throttle:20,1')->name('orders.pay');
    Route::post('/orders/{order}/check', [OrderController::class, 'check'])->middleware('throttle:30,1')->name('orders.check');
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
    Route::post('/billing/voucher', [BillingController::class, 'voucher'])->middleware('throttle:20,1')->name('billing.voucher');
    Route::post('/billing/cancel', [BillingController::class, 'cancel'])->name('billing.cancel');
    Route::get('/billing/invoices', [BillingController::class, 'invoices'])->name('billing.invoices');
    Route::get('/billing/invoices/{invoice}', [BillingController::class, 'invoice'])->name('billing.invoice');
    Route::post('/billing/invoices/{invoice}/pay', [BillingController::class, 'pay'])->name('billing.pay');
    Route::post('/billing/invoices/{invoice}/check', [BillingController::class, 'check'])->middleware('throttle:30,1')->name('billing.check');
    Route::post('/billing/invoices/{invoice}/simulate', [BillingController::class, 'simulate'])->name('billing.simulate');

    Route::get('/addons', [AddonController::class, 'index'])->name('addons');
    Route::post('/addons/{addon}/interest', [AddonController::class, 'interest'])->middleware('throttle:20,1')->name('addons.interest');
    Route::delete('/addons/{addon}/interest', [AddonController::class, 'withdraw'])->name('addons.withdraw');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications');
    Route::post('/notifications/read', [NotificationController::class, 'read'])->name('notifications.read');
    Route::get('/stream', StreamController::class)->name('stream');
});
