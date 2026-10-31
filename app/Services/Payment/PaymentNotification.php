<?php

namespace App\Services\Payment;

/**
 * Kabar status pembayaran yang sudah dinormalisasi — dari webhook, cek status API, simulator, staf (manual),
 * atau voucher 100%. Satu-satunya masukan untuk ApplyPayment.
 */
final class PaymentNotification
{
    public const PAID = 'paid';

    public const PENDING = 'pending';

    public const FAILED = 'failed';

    public function __construct(
        public readonly string $provider,
        public readonly string $externalId, // unik per kabar (mis. id event / id pembayaran + status) → idempoten
        public readonly string $invoiceRef, // nomor invoice atau public_id invoice
        public readonly string $status, // paid | pending | failed
        public readonly ?int $amount,
        public readonly ?string $method,
        public readonly bool $signatureValid,
        public readonly array $payload,
    ) {}
}
