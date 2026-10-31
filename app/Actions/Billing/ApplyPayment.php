<?php

namespace App\Actions\Billing;

use App\Authz\PlanFeatures;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PaymentEvent;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Services\Payment\PaymentNotification;
use App\Services\Payment\PaymentService;
use App\Support\Audit;
use App\Support\Notify;
use Illuminate\Support\Facades\DB;

/**
 * Satu jalur untuk semua notifikasi pembayaran (webhook gateway & simulator):
 *  1. simpan event mentah — unik (provider, external_id), jadi webhook ganda tidak diproses dua kali;
 *  2. tolak bila signature salah, nominal beda, atau invoice tidak bisa dibayar;
 *  3. lunas → invoice `paid`, langganan aktif/diperpanjang, paket workspace diganti, notifikasi & audit.
 */
final class ApplyPayment
{
    /** @return string hasil: paid | ignored | duplicate | rejected | pending | failed */
    public function handle(PaymentNotification $e): string
    {
        // referensi dari gateway = public_id invoice (order_id); kabar internal memakai nomor invoice
        $invoice = Invoice::acrossTenants()->where(fn ($q) => $q->where('number', $e->invoiceRef)->orWhere('public_id', $e->invoiceRef))->first();

        return DB::transaction(function () use ($e, $invoice) {
            $event = PaymentEvent::firstOrCreate(
                ['provider' => $e->provider, 'external_id' => $e->externalId],
                ['tenant_id' => $invoice?->tenant_id, 'invoice_id' => $invoice?->id, 'type' => $e->status,
                    'amount' => $e->amount, 'payload' => $e->payload, 'signature_valid' => $e->signatureValid],
            );
            if (! $event->wasRecentlyCreated) {
                return 'duplicate';
            }
            if (! $e->signatureValid || ! $invoice) {
                return 'rejected';
            }
            $invoice = Invoice::acrossTenants()->whereKey($invoice->id)->lockForUpdate()->first();
            if ($invoice->status === 'paid') {
                return 'ignored';
            }
            if ($e->status === PaymentNotification::PENDING) {
                return 'pending';
            }
            if ($e->status === PaymentNotification::FAILED) {
                $event->update(['processed_at' => now()]);

                return 'failed'; // invoice tetap terbuka; pengguna bisa mencoba metode lain
            }
            if ($e->amount !== null && $e->amount !== (int) $invoice->total) {
                return 'rejected'; // nominal tidak cocok → jangan aktifkan paket
            }

            $invoice->update(['status' => 'paid', 'paid_at' => now(), 'payment_method' => $e->method]);
            $event->update(['processed_at' => now()]);

            if ($invoice->type === 'order') {
                $this->orderPaid($invoice);

                return 'paid';
            }

            $sub = $this->activate($invoice);
            $invoice->update(['subscription_id' => $sub->id]);
            $this->redeem($invoice);

            Audit::tenant($invoice->tenant_id, 'billing.paid', $invoice, null, ['number' => $invoice->number, 'total' => $invoice->total, 'plan' => $sub->plan_id]);
            if ($invoice->user_id) {
                Notify::user($invoice->user_id, $invoice->tenant_id, 'invoice_paid', [
                    'id' => $invoice->public_id, 'number' => $invoice->number, 'plan' => $invoice->plan?->name, 'until' => $sub->current_period_end->toIso8601String(),
                ]);
            }

            return 'paid';
        });
    }

    /** Pesanan kartu/lanyard lunas → antre produksi; staf memulai produksi dari panel → Pesanan. */
    private function orderPaid(Invoice $invoice): void
    {
        $order = Order::withoutGlobalScopes()->whereKey($invoice->order_id)->lockForUpdate()->first();
        if (! $order || $order->status !== 'pending_payment') {
            return; // dibatalkan / sudah diproses — staf menangani pengembalian dana secara manual
        }
        $order->update(['status' => 'paid', 'paid_at' => now()]);
        Audit::tenant($order->tenant_id, 'order.paid', $order, null, ['number' => $order->number, 'total' => $order->total, 'invoice' => $invoice->number]);
        if ($order->user_id) {
            Notify::user($order->user_id, $order->tenant_id, 'order_status', ['id' => $order->public_id, 'number' => $order->number, 'status' => 'paid', 'label' => $order->label()]);
        }
    }

    private function activate(Invoice $invoice): Subscription
    {
        $tenant = Tenant::findOrFail($invoice->tenant_id);
        $plan = Plan::findOrFail($invoice->plan_id);
        $months = PaymentService::CYCLES[$invoice->cycle] ?? 1;
        $sub = Subscription::acrossTenants()->where('tenant_id', $tenant->id)->lockForUpdate()->first();

        // paket sama & masih berjalan → diperpanjang dari akhir periode; selain itu mulai sekarang
        $extend = $sub && $sub->plan_id === $plan->id && in_array($sub->status, ['active', 'trialing', 'past_due'], true) && $sub->current_period_end->isFuture();
        $start = $extend ? $sub->current_period_start : now();
        $end = ($extend ? $sub->current_period_end : now())->copy()->addMonthsNoOverflow($months);

        $attrs = [
            'plan_id' => $plan->id, 'cycle' => $invoice->cycle, 'status' => 'active', 'current_period_start' => $start,
            'current_period_end' => $end, 'grace_until' => null, 'cancel_at_period_end' => false, 'cancelled_at' => null,
        ];
        $sub ? $sub->update($attrs) : $sub = Subscription::create(['tenant_id' => $tenant->id] + $attrs);

        $tenant->update(['plan_id' => $plan->id, 'status' => $tenant->status->value === 'past_due' ? 'active' : $tenant->status->value]);
        PlanFeatures::forget($tenant);

        return $sub;
    }

    /** Voucher dihitung terpakai hanya saat invoice lunas (1x per workspace: unique voucher_id+tenant_id). */
    private function redeem(Invoice $invoice): void
    {
        if (! $invoice->voucher_id) {
            return;
        }
        $r = VoucherRedemption::firstOrCreate(['voucher_id' => $invoice->voucher_id, 'tenant_id' => $invoice->tenant_id],
            ['invoice_id' => $invoice->id, 'amount' => (int) $invoice->discount]);
        if ($r->wasRecentlyCreated) {
            Voucher::whereKey($invoice->voucher_id)->increment('redeemed_count');
        }
    }
}
