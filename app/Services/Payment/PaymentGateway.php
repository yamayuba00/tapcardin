<?php

namespace App\Services\Payment;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Http\Request;

/** Kontrak gateway pembayaran. Tambah penyedia baru = satu kelas di Gateways/ + nama driver di PaymentService. */
interface PaymentGateway
{
    /** Kode penyedia yang disimpan di invoice & payment_events (maks 16 karakter). */
    public function code(): string;

    /** Nama yang ditampilkan ke pengguna. */
    public function label(): string;

    /** Buat sesi bayar untuk invoice. @return array{url:string, ref:string} halaman/link bayar & id pembayaran di gateway */
    public function checkout(Invoice $invoice, User $payer, string $returnUrl): array;

    /** Webhook masuk → kabar ternormalisasi; null bila bukan untuk kita / tidak terbaca. */
    public function parseWebhook(Request $request): ?PaymentNotification;

    /** Tanya status pembayaran invoice langsung ke gateway (server-to-server); null bila tidak didukung / belum ada. */
    public function fetchStatus(Invoice $invoice): ?PaymentNotification;
}
