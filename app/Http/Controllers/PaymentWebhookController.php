<?php

namespace App\Http\Controllers;

use App\Actions\Billing\ApplyPayment;
use App\Support\Billing\Payments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST {base}/webhooks/payments/{provider} — notifikasi gateway (tanpa CSRF, tanpa sesi).
 * Selalu membalas 200 untuk event yang terbaca agar gateway tidak mengulang tanpa henti; keputusan
 * (lunas / ditolak / duplikat) tercatat di payment_events.
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, ApplyPayment $apply): JsonResponse
    {
        $gw = Payments::gatewayFor($provider);
        abort_unless($gw, 404);
        $event = $gw->parseWebhook($request);
        if (! $event) {
            return response()->json(['ok' => false], 400);
        }

        return response()->json(['ok' => true, 'outcome' => $apply->handle($event)]);
    }
}
