<?php

namespace App\Actions\Billing;

use App\Exceptions\ApiError;
use App\Models\Card;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Payment\PaymentService;
use App\Support\CardPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Perpanjang masa pakai kartu penjualan langsung: invoice `card_renewal` untuk satu kartu, dipakai ulang selama masih
 * terbuka, lalu halaman bayar gateway. Lunas → ApplyPayment memperpanjang `valid_until` kartu itu.
 */
final class CreateCardRenewal
{
    /** @return array{invoice: Invoice, payment_url: ?string} */
    public function handle(Card $card, User $user, string $origin): array
    {
        if ($card->valid_until === null || $card->status->value === 'revoked' || $card->holder_user_id !== $user->id) {
            throw new ApiError('renewal_unavailable', 'Kartu ini tidak bisa diperpanjang.');
        }
        $price = CardPeriod::renewalPrice();
        $months = CardPeriod::months();

        $invoice = DB::transaction(function () use ($card, $user, $price, $months) {
            $open = Invoice::where('tenant_id', $card->tenant_id)->where('type', 'card_renewal')->where('status', 'issued')
                ->where('due_at', '>', now())->whereRaw("(billing_snapshot->>'card_id')::bigint = ?", [$card->id])
                ->where('total', $price)->lockForUpdate()->first();
            if ($open) {
                return $open;
            }
            $label = "Perpanjang masa pakai kartu {$card->formattedShortCode()} — {$months} bulan";
            $inv = Invoice::create([
                'tenant_id' => $card->tenant_id, 'user_id' => $user->id, 'number' => 'TMP-'.Str::random(16),
                'type' => 'card_renewal', 'status' => 'issued',
                'lines' => [['code' => "card-renewal-{$months}m", 'label' => $label, 'qty' => 1, 'amount' => $price]],
                'subtotal' => $price, 'discount' => 0, 'tax' => 0, 'total' => $price,
                'billing_snapshot' => ['name' => $user->name, 'email' => $user->email, 'card_id' => $card->id, 'months' => $months, 'label' => $label],
                'issued_at' => now(), 'due_at' => $expires = now()->addMinutes((int) config('payment.invoice_ttl_minutes')), 'expires_at' => $expires,
            ]);
            $inv->update(['number' => sprintf('INV/%s/%06d', now('Asia/Jakarta')->format('Y/m'), $inv->id)]);

            return $inv;
        });

        return ['invoice' => $invoice, 'payment_url' => PaymentService::startPayment($invoice, $user, "$origin/billing/invoices/{$invoice->public_id}")];
    }
}
