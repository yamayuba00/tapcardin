<?php

namespace App\Support\Billing;

/** Notifikasi pembayaran yang sudah dinormalisasi dari gateway mana pun. */
final class WebhookEvent
{
    public const PAID = 'paid';

    public const PENDING = 'pending';

    public const FAILED = 'failed';

    public function __construct(
        public readonly string $provider,
        public readonly string $externalId, // unik per event (mis. transaksi + status) → idempoten
        public readonly string $invoiceNumber,
        public readonly string $status, // paid | pending | failed
        public readonly ?int $amount,
        public readonly ?string $method,
        public readonly bool $signatureValid,
        public readonly array $payload,
    ) {}
}
