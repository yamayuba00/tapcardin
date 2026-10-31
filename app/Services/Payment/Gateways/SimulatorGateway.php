<?php

namespace App\Services\Payment\Gateways;

use App\Models\Invoice;
use App\Models\User;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Gateway simulasi untuk pengembangan lokal & test: halaman bayar ada di app (/billing/pay/{invoice}),
 * tombol "Bayar" mengirim kabar yang diproses lewat jalur yang sama dengan gateway asli.
 * Ditolak di produksi (lihat PaymentService::gateway).
 */
final class SimulatorGateway implements PaymentGateway
{
    public function code(): string
    {
        return 'simulator';
    }

    public function label(): string
    {
        return 'Simulator';
    }

    public function checkout(Invoice $invoice, User $payer, string $returnUrl): array
    {
        // tetap di host workspace yang sama dengan halaman kembali (app. atau {slug}.)
        $u = parse_url($returnUrl);
        $origin = ($u['scheme'] ?? 'http').'://'.($u['host'] ?? 'localhost').(isset($u['port']) ? ':'.$u['port'] : '');

        return ['url' => "$origin/billing/pay/{$invoice->public_id}", 'ref' => $invoice->number];
    }

    public function parseWebhook(Request $request): ?PaymentNotification
    {
        return null; // simulator tidak menerima webhook dari luar
    }

    public function fetchStatus(Invoice $invoice): ?PaymentNotification
    {
        return null;
    }

    /** Kabar bayar/gagal dari halaman simulasi. */
    public static function notification(Invoice $invoice, string $result, string $method): PaymentNotification
    {
        return new PaymentNotification(
            provider: 'simulator', externalId: 'sim-'.Str::ulid(), invoiceRef: $invoice->number,
            status: $result === 'paid' ? PaymentNotification::PAID : PaymentNotification::FAILED,
            amount: (int) $invoice->total, method: $method, signatureValid: true, payload: ['result' => $result, 'method' => $method],
        );
    }
}
