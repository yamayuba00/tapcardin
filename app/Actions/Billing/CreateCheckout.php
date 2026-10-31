<?php

namespace App\Actions\Billing;

use App\Exceptions\ApiError;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Support\Billing\Payments;
use App\Support\Billing\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Upgrade / perpanjang paket: buat invoice langganan (atau pakai ulang yang masih terbuka untuk paket & siklus
 * yang sama), lalu minta halaman bayar ke gateway. Harga selalu dari tabel `plans` — tidak pernah dari klien.
 */
final class CreateCheckout
{
    /** @return array{invoice: Invoice, payment_url: ?string} */
    public function handle(Tenant $tenant, User $user, Plan $plan, string $cycle, string $returnUrl, ?string $voucherCode = null): array
    {
        if (! $plan->is_public || $plan->tenant_type !== $tenant->type->value) {
            throw new ApiError('plan_unavailable', 'Paket ini tidak tersedia untuk workspace Anda.');
        }
        $amount = Payments::price($plan, $cycle);
        if ($amount <= 0) {
            throw new ApiError('plan_free', 'Paket gratis tidak perlu dibayar.');
        }

        $voucher = $voucherCode ? self::voucher($voucherCode, $tenant, $plan) : null;
        $discount = $voucher?->discountFor($amount) ?? 0;

        $invoice = DB::transaction(function () use ($tenant, $user, $plan, $cycle, $amount, $voucher, $discount) {
            $open = Invoice::where('tenant_id', $tenant->id)->where('type', 'subscription')->where('status', 'issued')
                ->where('plan_id', $plan->id)->where('cycle', $cycle)->where('due_at', '>', now())
                ->where('voucher_id', $voucher?->id)->lockForUpdate()->first();
            if ($open) {
                return $open;
            }
            // invoice terbuka untuk paket/siklus lain dibatalkan agar tidak ada dua tagihan aktif
            Invoice::where('tenant_id', $tenant->id)->where('type', 'subscription')->where('status', 'issued')->update(['status' => 'void']);

            $sub = Subscription::where('tenant_id', $tenant->id)->first();
            $months = Payments::CYCLES[$cycle];
            $label = "Paket {$plan->name} — ".($cycle === 'yearly' ? '1 tahun' : '1 bulan');
            $lines = [['code' => "{$plan->key}-{$cycle}", 'label' => $label, 'qty' => 1, 'amount' => $amount]];
            if ($cycle === 'yearly' && ($save = Payments::yearlySaving($plan)) > 0) {
                $lines[0]['note'] = 'Hemat '.number_format($save, 0, ',', '.').' dibanding bulanan';
            }
            if ($discount > 0) {
                $lines[] = ['code' => 'voucher-'.$voucher->code, 'label' => "Voucher {$voucher->code}", 'qty' => 1, 'amount' => -$discount];
            }

            $inv = Invoice::create([
                'tenant_id' => $tenant->id, 'user_id' => $user->id, 'number' => 'TMP-'.Str::random(16),
                'type' => 'subscription', 'status' => 'issued', 'subscription_id' => $sub?->id, 'plan_id' => $plan->id, 'cycle' => $cycle,
                'lines' => $lines, 'subtotal' => $amount, 'discount' => $discount, 'tax' => 0, 'total' => $amount - $discount, 'voucher_id' => $voucher?->id,
                'billing_snapshot' => ['name' => $user->name, 'email' => $user->email, 'workspace' => $tenant->name, 'months' => $months],
                'issued_at' => now(), 'due_at' => now()->addDay(),
            ]);
            // nomor berurutan & unik dari id: INV/2026/10/000123
            $inv->update(['number' => sprintf('INV/%s/%06d', now('Asia/Jakarta')->format('Y/m'), $inv->id)]);

            return $inv;
        });

        // voucher 100% -> tanpa gateway; langsung lunas lewat jalur pembayaran yang sama
        if ((int) $invoice->total === 0) {
            app(ApplyPayment::class)->handle(new WebhookEvent('voucher', 'voucher-'.$invoice->number, $invoice->number, WebhookEvent::PAID, 0, 'voucher', true, ['voucher' => $voucher?->code]));

            return ['invoice' => $invoice->fresh('plan'), 'payment_url' => null];
        }
        if (! $invoice->payment_url) {
            $gw = Payments::gateway();
            $pay = $gw->checkout($invoice, $user, $returnUrl);
            $invoice->update(['payment_provider' => $gw->name(), 'payment_url' => $pay['url'], 'payment_ref' => $pay['ref']]);
        }

        return ['invoice' => $invoice->fresh('plan'), 'payment_url' => $invoice->payment_url];
    }

    /** Voucher yang sah untuk workspace & paket ini; selain itu ApiError dengan alasan yang jelas. */
    public static function voucher(string $code, Tenant $tenant, Plan $plan): Voucher
    {
        $v = Voucher::where('code', strtoupper(trim($code)))->first();
        $error = match (true) {
            ! $v || ! $v->is_active => 'Kode voucher tidak ditemukan.',
            ($v->starts_at && $v->starts_at->isFuture()) || ($v->ends_at && $v->ends_at->isPast()) => 'Voucher sudah tidak berlaku.',
            $v->max_redemptions && $v->redeemed_count >= $v->max_redemptions => 'Kuota voucher sudah habis.',
            $v->plan_keys && ! in_array($plan->key, $v->plan_keys, true) => "Voucher tidak berlaku untuk paket {$plan->name}.",
            VoucherRedemption::where('voucher_id', $v->id)->where('tenant_id', $tenant->id)->exists() => 'Voucher ini sudah pernah dipakai workspace Anda.',
            default => null,
        };
        if ($error) {
            throw new ApiError('voucher_invalid', $error);
        }

        return $v;
    }
}
