<?php

namespace App\Support\Billing;

use App\Exceptions\ApiError;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Midtrans Snap (VA bank, QRIS, GoPay, ShopeePay, kartu kredit). Checkout membuat transaksi Snap dengan
 * order_id = nomor invoice; notifikasi diverifikasi dengan signature_key = SHA-512(order_id + status_code +
 * gross_amount + server_key) dan nominal harus sama dengan total invoice (dicek di ApplyPayment).
 */
final class MidtransGateway implements PaymentGateway
{
    public function __construct(private readonly string $serverKey, private readonly bool $production) {}

    public function name(): string
    {
        return 'midtrans';
    }

    public function checkout(Invoice $invoice, User $payer, string $returnUrl): array
    {
        if ($this->serverKey === '') {
            throw new ApiError('payment_unavailable', 'Pembayaran online belum dikonfigurasi. Hubungi admin TapCard.', 503);
        }
        $res = Http::withBasicAuth($this->serverKey, '')->acceptJson()->timeout(15)
            ->post(($this->production ? 'https://app.midtrans.com' : 'https://app.sandbox.midtrans.com').'/snap/v1/transactions', [
                'transaction_details' => ['order_id' => $invoice->number, 'gross_amount' => (int) $invoice->total],
                'customer_details' => ['first_name' => mb_substr($payer->name, 0, 50), 'email' => $payer->email],
                'item_details' => array_map(fn ($l) => [
                    'id' => mb_substr($l['code'] ?? 'item', 0, 50), 'name' => mb_substr($l['label'], 0, 50),
                    'price' => (int) $l['amount'], 'quantity' => (int) ($l['qty'] ?? 1),
                ], $invoice->lines),
                'callbacks' => ['finish' => $returnUrl],
                'expiry' => ['unit' => 'hour', 'duration' => 24],
            ]);
        if (! $res->successful() || ! $res->json('redirect_url')) {
            report(new \RuntimeException('Midtrans checkout gagal: '.$res->status().' '.$res->body()));
            throw new ApiError('payment_unavailable', 'Gateway pembayaran sedang bermasalah. Coba lagi beberapa saat lagi.', 502);
        }

        return ['url' => $res->json('redirect_url'), 'ref' => $invoice->number];
    }

    public function parseWebhook(Request $request): ?WebhookEvent
    {
        $p = $request->json()->all() ?: $request->all();
        $orderId = (string) ($p['order_id'] ?? '');
        if ($orderId === '') {
            return null;
        }
        $expected = hash('sha512', $orderId.($p['status_code'] ?? '').($p['gross_amount'] ?? '').$this->serverKey);
        $valid = $this->serverKey !== '' && hash_equals($expected, (string) ($p['signature_key'] ?? ''));
        $tx = $p['transaction_status'] ?? '';
        $status = match (true) {
            $tx === 'settlement', $tx === 'capture' && ($p['fraud_status'] ?? 'accept') === 'accept' => WebhookEvent::PAID,
            in_array($tx, ['deny', 'cancel', 'expire', 'failure'], true) => WebhookEvent::FAILED,
            default => WebhookEvent::PENDING,
        };

        return new WebhookEvent(
            provider: 'midtrans',
            externalId: mb_substr(($p['transaction_id'] ?? $orderId).':'.$tx, 0, 120),
            invoiceNumber: $orderId,
            status: $status,
            amount: isset($p['gross_amount']) ? (int) round((float) $p['gross_amount']) : null,
            method: isset($p['payment_type']) ? mb_substr(str_replace('_', ' ', $p['payment_type']).(isset($p['va_numbers'][0]['bank']) ? ' '.strtoupper($p['va_numbers'][0]['bank']) : ''), 0, 40) : null,
            signatureValid: $valid,
            payload: $p,
        );
    }
}
