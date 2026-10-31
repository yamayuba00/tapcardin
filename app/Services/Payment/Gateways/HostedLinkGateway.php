<?php

namespace App\Services\Payment\Gateways;

use App\Exceptions\ApiError;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentNotification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gateway "payment link" via REST (mis. QRIS). Seluruh alamat, header, dan cara verifikasi dari config/payment.php.
 *
 *   buat bayar   POST {api_url}{create_path}  {order_id, amount, currency, payment_method_type_code, description,
 *                                              success_return_url, cancel_return_url, expires_in_hours, metadata}
 *                → {payment_id|id, payment_link_url|qr_code_url, status}
 *   cek status   GET  {api_url}{status_path}  ({id} = payment_id) → {status, amount, order_id, payment_method}
 *   webhook      {type|event_type, id|event_id, data: {payment_id, order_id, amount, status, payment_method}}
 *
 * order_id = public_id invoice (aman dipakai di URL/API mana pun). Isi webhook baru dipercaya bila tanda tangannya
 * sah; bila tidak bisa diverifikasi, status dikonfirmasi ulang lewat API — jadi uang tidak pernah "lunas" hanya karena
 * ada request masuk.
 */
final class HostedLinkGateway implements PaymentGateway
{
    private const PAID = ['paid', 'completed', 'complete', 'success', 'succeeded', 'settled', 'settlement', 'capture'];

    private const FAILED = ['failed', 'expired', 'cancelled', 'canceled', 'void', 'deny', 'denied', 'rejected'];

    public function __construct(private readonly array $c) {}

    public function code(): string
    {
        return mb_substr((string) ($this->c['code'] ?? 'gateway'), 0, 16);
    }

    public function label(): string
    {
        return (string) ($this->c['name'] ?? 'Payment Gateway');
    }

    public function checkout(Invoice $invoice, User $payer, string $returnUrl): array
    {
        // kembali ke halaman invoice / pesanan di host workspace yang sama; halaman itu menanyakan status ke gateway
        $back = rtrim((string) preg_replace('/([?&])paid=1(&|$)/', '$1', $returnUrl), '?&');
        $body = array_filter([
            'order_id' => $invoice->public_id,
            'amount' => (int) $invoice->total,
            'currency' => 'IDR',
            'payment_method_type_code' => $this->c['method'] ?? null,
            'description' => "TapCard {$invoice->number}",
            'success_return_url' => $back.(str_contains($back, '?') ? '&' : '?').'paid=1',
            'cancel_return_url' => $back,
            'expires_in_hours' => (int) ($this->c['expires_hours'] ?? 24) ?: null,
            'metadata' => ['invoice_number' => $invoice->number, 'workspace' => $invoice->tenant_id],
        ], fn ($v) => $v !== null && $v !== '');

        $res = $this->call('post', (string) ($this->c['create_path'] ?? '/payments'), $body);
        $data = $res['data'] ?? $res;
        $url = $data['payment_link_url'] ?? $data['payment_url'] ?? $data['checkout_url'] ?? $data['qr_code_url'] ?? null;
        $ref = $data['payment_id'] ?? $data['id'] ?? null;
        if (! $url || ! $ref) {
            Log::warning('payment.checkout_unexpected_response', ['gateway' => $this->code(), 'keys' => array_keys($data)]);
            throw new ApiError('payment_unavailable', 'Halaman pembayaran belum bisa dibuat. Coba beberapa saat lagi.', 502);
        }

        return ['url' => (string) $url, 'ref' => (string) $ref];
    }

    public function fetchStatus(Invoice $invoice): ?PaymentNotification
    {
        if (! $invoice->payment_ref || $invoice->payment_provider !== $this->code()) {
            return null;
        }
        try {
            $res = $this->call('get', str_replace('{id}', rawurlencode($invoice->payment_ref), (string) ($this->c['status_path'] ?? '/payments/{id}')));
        } catch (ApiError) {
            return null; // gateway sedang tidak bisa dihubungi → dicoba lagi oleh jadwal rekonsiliasi
        }
        $data = $res['data'] ?? $res;
        $status = $this->status((string) ($data['status'] ?? ''));

        return new PaymentNotification(
            provider: $this->code(),
            externalId: 'status-'.$invoice->payment_ref.'-'.$status,
            invoiceRef: $invoice->public_id,
            status: $status,
            amount: isset($data['amount']) ? (int) $data['amount'] : null,
            method: $this->method($data),
            signatureValid: true, // jawaban langsung dari API gateway (server-to-server, ber-API key)
            payload: $data,
        );
    }

    public function parseWebhook(Request $request): ?PaymentNotification
    {
        $body = json_decode($request->getContent(), true);
        if (! is_array($body)) {
            return null;
        }
        $data = is_array($body['data'] ?? null) ? $body['data'] : $body;
        $ref = $data['order_id'] ?? $data['metadata']['invoice_number'] ?? null;
        if (! $ref) {
            return null;
        }
        $type = strtolower((string) ($body['type'] ?? $body['event_type'] ?? $body['event'] ?? ''));
        $status = str_ends_with($type, '.completed') || str_ends_with($type, '.paid') || str_ends_with($type, '.succeeded')
            ? PaymentNotification::PAID
            : (str_ends_with($type, '.failed') || str_ends_with($type, '.expired') ? PaymentNotification::FAILED : $this->status((string) ($data['status'] ?? '')));
        $eventId = $request->header('svix-id') ?? $request->header('webhook-id') ?? $body['id'] ?? $body['event_id'] ?? null;

        return new PaymentNotification(
            provider: $this->code(),
            externalId: (string) ($eventId ?? 'wh-'.hash('sha256', $request->getContent())),
            invoiceRef: (string) $ref,
            status: $status,
            amount: isset($data['amount']) ? (int) $data['amount'] : null,
            method: $this->method($data),
            signatureValid: $this->verified($request),
            payload: $body,
        );
    }

    /** Tanda tangan webhook: Svix (id.timestamp.body, HMAC-SHA256, secret base64 "whsec_…") atau token statis. */
    private function verified(Request $request): bool
    {
        $w = $this->c['webhook'] ?? [];
        $mode = $w['verify'] ?? 'signature';
        if ($mode === 'token') {
            $token = (string) ($w['token'] ?? '');

            return $token !== '' && hash_equals($token, (string) $request->header($w['token_header'] ?? 'X-Webhook-Token'));
        }
        if ($mode !== 'signature' || empty($w['secret'])) {
            return false;
        }
        $id = $request->header('svix-id') ?? $request->header('webhook-id');
        $ts = $request->header('svix-timestamp') ?? $request->header('webhook-timestamp');
        $sig = $request->header('svix-signature') ?? $request->header('webhook-signature');
        if (! $id || ! $ts || ! $sig || ! ctype_digit((string) $ts) || abs(now()->getTimestamp() - (int) $ts) > (int) ($w['tolerance'] ?? 300)) {
            return false;
        }
        $secret = base64_decode(preg_replace('/^whsec_/', '', (string) $w['secret']), true);
        if ($secret === false) {
            return false;
        }
        $expected = base64_encode(hash_hmac('sha256', "$id.$ts.".$request->getContent(), $secret, true));
        foreach (preg_split('/\s+/', trim((string) $sig)) as $part) {
            [$ver, $value] = array_pad(explode(',', $part, 2), 2, '');
            if ($ver === 'v1' && hash_equals($expected, $value)) {
                return true;
            }
        }

        return false;
    }

    private function status(string $raw): string
    {
        $s = strtolower(trim($raw));

        return in_array($s, self::PAID, true) ? PaymentNotification::PAID
            : (in_array($s, self::FAILED, true) ? PaymentNotification::FAILED : PaymentNotification::PENDING);
    }

    private function method(array $data): string
    {
        $m = $data['payment_method'] ?? $data['payment_method_type_code'] ?? $this->c['method'] ?? 'QRIS';

        return mb_substr(is_array($m) ? (string) ($m['name'] ?? $m['type'] ?? 'QRIS') : (string) $m, 0, 40);
    }

    private function call(string $verb, string $path, array $body = []): array
    {
        $base = rtrim((string) ($this->c['api_url'] ?? ''), '/');
        if ($base === '' || empty($this->c['api_key'])) {
            throw new ApiError('payment_unavailable', 'Pembayaran online belum dikonfigurasi. Hubungi admin TapCard.', 503);
        }
        try {
            $res = Http::acceptJson()->asJson()
                ->withHeaders([(string) ($this->c['api_key_header'] ?? 'X-Api-Key') => (string) $this->c['api_key']])
                ->timeout((int) ($this->c['timeout'] ?? 20))->retry(2, 300, fn (Throwable $e) => $e instanceof ConnectionException, throw: false)
                ->{$verb}($base.'/'.ltrim($path, '/'), $body);
        } catch (ConnectionException $e) {
            Log::warning('payment.gateway_unreachable', ['gateway' => $this->code(), 'error' => $e->getMessage()]);
            throw new ApiError('payment_unavailable', 'Gateway pembayaran sedang tidak bisa dihubungi. Coba beberapa saat lagi.', 502);
        }
        if (! $res->successful()) {
            Log::warning('payment.gateway_error', ['gateway' => $this->code(), 'status' => $res->status(), 'message' => $res->json('message')]);
            throw new ApiError('payment_unavailable', 'Gateway pembayaran menolak permintaan. Coba beberapa saat lagi.', 502);
        }

        return (array) $res->json();
    }
}
