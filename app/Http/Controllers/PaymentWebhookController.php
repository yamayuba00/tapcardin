<?php

namespace App\Http\Controllers;

use App\Actions\Billing\ApplyPayment;
use App\Models\Invoice;
use App\Models\PaymentEvent;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST {base}/webhooks/payment — kabar dari gateway pembayaran aktif (tanpa CSRF, tanpa sesi).
 * Tanda tangan sah → isi webhook diproses. Tidak bisa diverifikasi → isi webhook diabaikan, status dikonfirmasi
 * langsung ke API gateway (server-to-server), jadi request palsu tidak pernah melunasi invoice.
 * Selalu membalas 200 untuk webhook yang terbaca agar gateway tidak mengulang tanpa henti.
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, ApplyPayment $apply): JsonResponse
    {
        $gw = PaymentService::gateway();
        $n = $gw->parseWebhook($request);
        if (! $n) {
            return response()->json(['ok' => false], 400);
        }
        if ($n->signatureValid) {
            return response()->json(['ok' => true, 'outcome' => $apply->handle($n)]);
        }

        // catat webhook tak terverifikasi untuk audit, lalu tanyakan status sebenarnya ke gateway
        $invoice = Invoice::acrossTenants()->where(fn ($q) => $q->where('public_id', $n->invoiceRef)->orWhere('number', $n->invoiceRef))->first();
        PaymentEvent::firstOrCreate(['provider' => $n->provider, 'external_id' => $n->externalId], [
            'tenant_id' => $invoice?->tenant_id, 'invoice_id' => $invoice?->id, 'type' => $n->status,
            'amount' => $n->amount, 'payload' => $n->payload, 'signature_valid' => false,
        ]);
        $confirmed = $invoice ? $gw->fetchStatus($invoice) : null;

        return response()->json(['ok' => true, 'outcome' => $confirmed ? $apply->handle($confirmed) : 'unverified']);
    }
}
