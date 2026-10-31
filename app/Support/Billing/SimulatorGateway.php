<?php

namespace App\Support\Billing;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Gateway simulasi untuk pengembangan lokal & test: halaman bayar ada di app (/billing/pay/{invoice}),
 * tombol "Bayar" mengirim event yang diproses lewat jalur yang sama dengan webhook asli.
 * Tidak pernah dipakai di produksi (lihat Payments::gateway).
 */
final class SimulatorGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'simulator';
    }

    public function checkout(Invoice $invoice, User $payer, string $returnUrl): array
    {
        // tetap di host workspace yang sama dengan halaman kembali (app. atau {slug}.)
        $u = parse_url($returnUrl);
        $origin = ($u['scheme'] ?? 'http').'://'.($u['host'] ?? 'localhost').(isset($u['port']) ? ':'.$u['port'] : '');

        return ['url' => "$origin/billing/pay/{$invoice->public_id}", 'ref' => $invoice->number];
    }

    public function parseWebhook(Request $request): ?WebhookEvent
    {
        return null; // simulator tidak menerima webhook dari luar
    }

    /** Event bayar/gagal dari halaman simulasi. */
    public static function event(Invoice $invoice, string $result, string $method): WebhookEvent
    {
        return new WebhookEvent(
            provider: 'simulator', externalId: 'sim-'.Str::ulid(), invoiceNumber: $invoice->number,
            status: $result === 'paid' ? WebhookEvent::PAID : WebhookEvent::FAILED,
            amount: (int) $invoice->total, method: $method, signatureValid: true, payload: ['result' => $result, 'method' => $method],
        );
    }
}
