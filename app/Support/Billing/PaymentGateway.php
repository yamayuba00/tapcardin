<?php

namespace App\Support\Billing;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Http\Request;

/** Gateway pembayaran: membuat halaman bayar untuk invoice & membaca notifikasi (webhook) yang sudah diverifikasi. */
interface PaymentGateway
{
    public function name(): string;

    /** @return array{url:string, ref:string} halaman bayar gateway & referensi transaksinya */
    public function checkout(Invoice $invoice, User $payer, string $returnUrl): array;

    /** Notifikasi gateway → event ternormalisasi; null bila bukan untuk kita / tidak bisa dibaca. */
    public function parseWebhook(Request $request): ?WebhookEvent;
}
