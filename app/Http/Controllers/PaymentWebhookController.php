<?php

namespace App\Http\Controllers;

use App\Actions\Billing\ApplyPayment;
use App\Models\Invoice;
use App\Models\PaymentEvent;
use App\Services\Payment\PaymentService;
use App\Support\Hasher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * POST wbh.{base}/payment (alamat lama {base}/webhooks/payment tetap diterima) — kabar dari gateway pembayaran
 * aktif (tanpa CSRF, tanpa sesi).
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

        // tak terverifikasi: dibatasi per IP (sumber sah selalu bertanda tangan), dicatat seperlunya — tanpa isi asli
        $ip = Hasher::ip($request->ip());
        if (! RateLimiter::attempt("pay-unverified:$ip", 20, fn () => true, 60)) {
            return response()->json(['ok' => false], 429);
        }
        $invoice = Invoice::acrossTenants()->where(fn ($q) => $q->where('public_id', $n->invoiceRef)->orWhere('number', $n->invoiceRef))->first();
        PaymentEvent::firstOrCreate(['provider' => $n->provider, 'external_id' => mb_substr($n->externalId, 0, 120)], [
            'tenant_id' => $invoice?->tenant_id, 'invoice_id' => $invoice?->id, 'type' => $n->status, 'amount' => $n->amount,
            'payload' => ['unverified' => true, 'ip_hash' => $ip, 'bytes' => strlen($request->getContent())], 'signature_valid' => false,
        ]);
        // satu konfirmasi ke gateway per invoice per menit — banjir request palsu tidak menjadi banjir panggilan API
        $confirmed = $invoice && RateLimiter::attempt("pay-confirm:{$invoice->id}", 1, fn () => true, 60) ? $gw->fetchStatus($invoice) : null;

        return response()->json(['ok' => true, 'outcome' => $confirmed ? $apply->handle($confirmed) : 'unverified']);
    }
}
