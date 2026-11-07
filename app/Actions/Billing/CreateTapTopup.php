<?php

namespace App\Actions\Billing;

use App\Exceptions\ApiError;
use App\Models\Card;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Payment\PaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tambahan kuota tap kartu digital (config tapcard.digital_card): invoice `topup` untuk satu kartu — dipakai ulang
 * selama masih terbuka — lalu halaman bayar gateway. Lunas → ApplyPayment menambah `tap_quota` kartu itu.
 */
final class CreateTapTopup
{
    /** @return array{invoice: Invoice, payment_url: ?string} */
    public function handle(Card $card, User $user, string $origin): array
    {
        if (! $card->isDigital() || $card->status->value === 'revoked') {
            throw new ApiError('topup_unavailable', 'Tambahan tap hanya untuk kartu digital yang masih berlaku.');
        }
        ['topup_taps' => $taps, 'topup_price' => $price] = config('tapcard.digital_card');

        $invoice = DB::transaction(function () use ($card, $user, $taps, $price) {
            $open = Invoice::where('tenant_id', $card->tenant_id)->where('type', 'topup')->where('status', 'issued')
                ->where('due_at', '>', now())->whereRaw("(billing_snapshot->>'card_id')::bigint = ?", [$card->id])
                ->where('total', $price)->lockForUpdate()->first();
            if ($open) {
                return $open;
            }
            $label = 'Tambahan '.number_format($taps, 0, ',', '.').' tap — kartu digital';
            $inv = Invoice::create([
                'tenant_id' => $card->tenant_id, 'user_id' => $user->id, 'number' => 'TMP-'.Str::random(16),
                'type' => 'topup', 'status' => 'issued',
                'lines' => [['code' => 'tap-topup-'.$taps, 'label' => $label, 'qty' => 1, 'amount' => $price]],
                'subtotal' => $price, 'discount' => 0, 'tax' => 0, 'total' => $price,
                'billing_snapshot' => ['name' => $user->name, 'email' => $user->email, 'card_id' => $card->id, 'taps' => $taps, 'label' => $label],
                'issued_at' => now(), 'due_at' => now()->addDay(),
            ]);
            $inv->update(['number' => sprintf('INV/%s/%06d', now('Asia/Jakarta')->format('Y/m'), $inv->id)]);

            return $inv;
        });

        return ['invoice' => $invoice, 'payment_url' => PaymentService::startPayment($invoice, $user, "$origin/billing/invoices/{$invoice->public_id}")];
    }
}
