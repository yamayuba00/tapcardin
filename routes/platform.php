<?php

use App\Http\Controllers\Auth\AdminLoginController;
use App\Http\Controllers\Auth\StaffPasswordResetController;
use App\Http\Controllers\Platform\AnnouncementController;
use App\Http\Controllers\Platform\ApprovalController;
use App\Http\Controllers\Platform\AuditController;
use App\Http\Controllers\Platform\BatchController;
use App\Http\Controllers\Platform\CardController;
use App\Http\Controllers\Platform\CatalogController;
use App\Http\Controllers\Platform\DashboardController;
use App\Http\Controllers\Platform\DomainController;
use App\Http\Controllers\Platform\FieldActivationController;
use App\Http\Controllers\Platform\FinanceController;
use App\Http\Controllers\Platform\MeController;
use App\Http\Controllers\Platform\OrderController;
use App\Http\Controllers\Platform\PaymentReviewController;
use App\Http\Controllers\Platform\PlanController;
use App\Http\Controllers\Platform\RefundController;
use App\Http\Controllers\Platform\StaffController;
use App\Http\Controllers\Platform\StationController;
use App\Http\Controllers\Platform\SystemController;
use App\Http\Controllers\Platform\TenantController;
use App\Http\Controllers\Platform\UptimeController;
use App\Http\Controllers\Platform\UserController;
use App\Http\Controllers\Platform\VoucherController;
use Illuminate\Support\Facades\Route;

/*
| panel.{base} — panel pemilik & produksi (PRD §10.9, BSD §8.5). Nama rute berprefix `admin.`.
*/
// Staf login dengan email + password (bukan OTP seperti pengguna workspace).
Route::post('/api/auth/login', [AdminLoginController::class, 'login'])->name('login');
Route::post('/api/auth/logout', [AdminLoginController::class, 'logout'])->name('logout');
Route::post('/api/auth/forgot-password', [StaffPasswordResetController::class, 'forgot'])->middleware('throttle:5,1')->name('password.forgot');
Route::post('/api/auth/reset-password', [StaffPasswordResetController::class, 'reset'])->middleware('throttle:10,1')->name('password.reset');

Route::middleware(['auth', 'platform'])->prefix('api')->name('api.')->group(function () {
    Route::get('/me', MeController::class)->name('me');
    Route::put('/me/password', [AdminLoginController::class, 'changePassword'])->name('me.password');
    Route::get('/dashboard', [DashboardController::class, 'show'])->name('dashboard');

    Route::get('/tenants', [TenantController::class, 'index'])->name('tenants');
    Route::get('/tenants/{tenant}', [TenantController::class, 'show'])->name('tenants.show');
    Route::get('/tenants/{tenant}/members', [TenantController::class, 'members'])->name('tenants.members');
    Route::get('/tenants/{tenant}/cards', [TenantController::class, 'cards'])->name('tenants.cards');
    Route::patch('/tenants/{tenant}', [TenantController::class, 'update'])->name('tenants.update');
    Route::post('/tenants/{tenant}/suspend', [TenantController::class, 'suspend'])->name('tenants.suspend');
    Route::post('/tenants/{tenant}/reactivate', [TenantController::class, 'reactivate'])->name('tenants.reactivate');

    Route::get('/users', [UserController::class, 'index'])->name('users');
    Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');
    Route::get('/users/{user}/cards', [UserController::class, 'cards'])->name('users.cards');
    Route::post('/users/{user}/block', [UserController::class, 'block'])->name('users.block');
    Route::post('/users/{user}/unblock', [UserController::class, 'unblock'])->name('users.unblock');
    Route::post('/users/{user}/logout-all', [UserController::class, 'logoutAll'])->name('users.logout-all');

    Route::get('/cards', [CardController::class, 'index'])->name('cards');
    Route::get('/cards/{token}', [CardController::class, 'show'])->name('cards.show');
    Route::post('/cards/{token}/status', [CardController::class, 'status'])->name('cards.status');
    Route::post('/cards/{token}/replacement-batch', [CardController::class, 'replacementBatch'])->name('cards.replacement-batch');
    Route::post('/cards/{token}/reissue-code', [CardController::class, 'reissueCode'])->name('cards.reissue');
    Route::post('/cards/{token}/assign', [CardController::class, 'assign'])->name('cards.assign');
    Route::post('/cards/{token}/encoded', [StationController::class, 'encoded'])->name('cards.encoded');
    Route::post('/cards/{token}/qc', [StationController::class, 'qcResult'])->name('cards.qc');
    Route::put('/cards/{token}/sale', [FieldActivationController::class, 'sale'])->name('cards.sale');

    // Aktivasi di tempat (penjualan langsung)
    Route::get('/field/card', [FieldActivationController::class, 'lookup'])->middleware('throttle:60,1')->name('field.lookup');
    Route::post('/field/activate', [FieldActivationController::class, 'activate'])->middleware('throttle:20,1')->name('field.activate');

    Route::get('/batches', [BatchController::class, 'index'])->name('batches');
    Route::post('/batches', [BatchController::class, 'store'])->name('batches.store');
    Route::get('/batches/{batch}', [BatchController::class, 'show'])->name('batches.show');
    Route::get('/batches/{batch}/cards', [BatchController::class, 'cards'])->name('batches.cards');
    Route::post('/batches/{batch}/generate', [BatchController::class, 'generate'])->name('batches.generate');
    Route::get('/batches/{batch}/bundle.csv', [BatchController::class, 'bundle'])->name('batches.bundle');
    Route::get('/batches/{batch}/print', [BatchController::class, 'print'])->name('batches.print');
    Route::post('/batches/{batch}/status', [BatchController::class, 'status'])->name('batches.status');
    Route::post('/batches/{batch}/ship', [BatchController::class, 'ship'])->name('batches.ship');
    Route::get('/batches/{batch}/next', [StationController::class, 'next'])->name('batches.next');
    Route::get('/stations/batches', [StationController::class, 'batches'])->name('stations.batches');

    Route::get('/staff', [StaffController::class, 'index'])->name('staff');
    Route::post('/staff', [StaffController::class, 'store'])->name('staff.store');
    Route::patch('/staff/{staff}', [StaffController::class, 'update'])->name('staff.update');
    Route::post('/staff/{staff}/reset-password', [StaffController::class, 'resetPassword'])->name('staff.reset-password');

    Route::get('/orders', [OrderController::class, 'index'])->name('orders');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('/orders/{order}/review', [OrderController::class, 'review'])->name('orders.review');
    Route::post('/orders/{order}/refunds', [RefundController::class, 'store'])->name('orders.refunds.store');
    Route::post('/orders/{order}/production', [OrderController::class, 'startProduction'])->name('orders.production');
    Route::post('/orders/{order}/ship', [OrderController::class, 'ship'])->name('orders.ship');
    Route::post('/orders/{order}/deliver', [OrderController::class, 'deliver'])->name('orders.deliver');
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');

    Route::get('/domains', [DomainController::class, 'index'])->name('domains');
    Route::post('/domains', [DomainController::class, 'store'])->name('domains.store');
    Route::post('/domains/{domain}/verify', [DomainController::class, 'verify'])->middleware('throttle:30,1')->name('domains.verify');
    Route::patch('/domains/{domain}', [DomainController::class, 'update'])->name('domains.update');
    Route::delete('/domains/{domain}', [DomainController::class, 'destroy'])->name('domains.destroy');

    Route::get('/plans', [PlanController::class, 'index'])->name('plans');
    Route::post('/plans', [PlanController::class, 'store'])->name('plans.store');
    Route::patch('/plans/{plan:key}', [PlanController::class, 'update'])->name('plans.update');

    Route::get('/finance/summary', [FinanceController::class, 'summary'])->name('finance.summary');
    Route::get('/finance/invoices', [FinanceController::class, 'invoices'])->name('finance.invoices');
    Route::get('/finance/invoices/{id}', [FinanceController::class, 'invoice'])->name('finance.invoice');
    Route::post('/finance/invoices/{id}/mark-paid', [FinanceController::class, 'markPaid'])->name('finance.mark-paid');
    Route::post('/finance/invoices/{id}/void', [FinanceController::class, 'void'])->name('finance.void');
    Route::get('/finance/events', [FinanceController::class, 'events'])->name('finance.events');
    Route::get('/finance/subscriptions', [FinanceController::class, 'subscriptions'])->name('finance.subscriptions');
    Route::post('/finance/grant', [FinanceController::class, 'grant'])->name('finance.grant');

    Route::get('/refunds', [RefundController::class, 'index'])->name('refunds');
    Route::get('/refunds/{refund}', [RefundController::class, 'show'])->name('refunds.show');
    Route::post('/refunds/{refund}/approve', [RefundController::class, 'approve'])->middleware('throttle:30,1')->name('refunds.approve');
    Route::post('/refunds/{refund}/reject', [RefundController::class, 'reject'])->middleware('throttle:30,1')->name('refunds.reject');
    Route::post('/refunds/{refund}/payout', [RefundController::class, 'payout'])->middleware('throttle:30,1')->name('refunds.payout');
    Route::post('/refunds/{refund}/cancel', [RefundController::class, 'cancel'])->name('refunds.cancel');

    Route::get('/approvals', [ApprovalController::class, 'index'])->name('approvals');
    Route::post('/approvals/{approval}/approve', [ApprovalController::class, 'approve'])->middleware('throttle:30,1')->name('approvals.approve');
    Route::post('/approvals/{approval}/reject', [ApprovalController::class, 'reject'])->middleware('throttle:30,1')->name('approvals.reject');
    Route::post('/approvals/{approval}/cancel', [ApprovalController::class, 'cancel'])->name('approvals.cancel');

    Route::get('/payment-reviews', [PaymentReviewController::class, 'index'])->name('payment-reviews');
    Route::post('/payment-reviews/{review}/resolve', [PaymentReviewController::class, 'resolve'])->name('payment-reviews.resolve');

    Route::get('/vouchers', [VoucherController::class, 'index'])->name('vouchers');
    Route::post('/vouchers', [VoucherController::class, 'store'])->name('vouchers.store');
    Route::patch('/vouchers/{voucher}', [VoucherController::class, 'update'])->name('vouchers.update');

    Route::get('/catalog', [CatalogController::class, 'show'])->name('catalog');
    Route::post('/catalog/lanyard-widths', [CatalogController::class, 'saveWidth'])->name('catalog.widths.store');
    Route::patch('/catalog/lanyard-widths/{id}', [CatalogController::class, 'saveWidth'])->name('catalog.widths.update');
    Route::post('/catalog/lanyard-hardware', [CatalogController::class, 'saveHardware'])->name('catalog.hardware.store');
    Route::patch('/catalog/lanyard-hardware/{id}', [CatalogController::class, 'saveHardware'])->name('catalog.hardware.update');
    Route::post('/catalog/card-products', [CatalogController::class, 'saveCardProduct'])->name('catalog.card-products.store');
    Route::patch('/catalog/card-products/{id}', [CatalogController::class, 'saveCardProduct'])->name('catalog.card-products.update');
    Route::post('/catalog/shipping', [CatalogController::class, 'saveShipping'])->name('catalog.shipping.store');
    Route::patch('/catalog/shipping/{id}', [CatalogController::class, 'saveShipping'])->name('catalog.shipping.update');
    Route::patch('/catalog/addons/{id}', [CatalogController::class, 'updateAddon'])->name('catalog.addons.update');
    Route::patch('/catalog/contact-types/{id}', [CatalogController::class, 'updateContactType'])->name('catalog.contact-types.update');

    Route::get('/announcements', [AnnouncementController::class, 'index'])->name('announcements');
    Route::post('/announcements', [AnnouncementController::class, 'store'])->name('announcements.store');

    Route::get('/audit', [AuditController::class, 'index'])->name('audit');
    Route::get('/system', [SystemController::class, 'show'])->name('system');

    Route::get('/uptime', [UptimeController::class, 'index'])->name('uptime');
    Route::post('/uptime/monitors', [UptimeController::class, 'store'])->name('uptime.store');
    Route::get('/uptime/monitors/{monitor}', [UptimeController::class, 'show'])->name('uptime.show');
    Route::patch('/uptime/monitors/{monitor}', [UptimeController::class, 'update'])->name('uptime.update');
    Route::delete('/uptime/monitors/{monitor}', [UptimeController::class, 'destroy'])->name('uptime.destroy');
    Route::post('/uptime/monitors/{monitor}/check', [UptimeController::class, 'check'])->middleware('throttle:20,1')->name('uptime.check');
    Route::post('/uptime/incidents', [UptimeController::class, 'storeIncident'])->name('uptime.incidents.store');
    Route::post('/uptime/incidents/{incident}/updates', [UptimeController::class, 'updateIncident'])->name('uptime.incidents.update');
    Route::delete('/uptime/incidents/{incident}', [UptimeController::class, 'destroyIncident'])->name('uptime.incidents.destroy');
    Route::put('/system/settings', [SystemController::class, 'settings'])->name('system.settings');
});
