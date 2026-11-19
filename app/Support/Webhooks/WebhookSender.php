<?php

namespace App\Support\Webhooks;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Notify;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Satu percobaan kirim: cek ulang URL (SSRF) → POST JSON bertanda tangan, IP dipatok ke hasil cek, tanpa ikut redirect,
 * batas waktu 2 dtk sambung / 5 dtk total. Mengembalikan detik jeda bila perlu dicoba ulang, atau null bila selesai.
 */
final class WebhookSender
{
    public const MAX_ATTEMPTS = 7; // 1 kirim + 6 ulang

    private const RESPONSE_MAX = 2048;

    public function attempt(WebhookDelivery $d, bool $retry = true): ?int
    {
        $ep = $d->endpoint;
        if (! $ep || (! $ep->is_active && $d->type !== 'ping')) {
            $d->update(['status' => 'failed', 'error' => 'Endpoint dinonaktifkan atau dihapus.', 'next_retry_at' => null]);

            return null;
        }
        $attempt = $d->attempts + 1;
        $started = microtime(true);
        $status = null;
        $response = null;
        $error = null;
        try {
            $target = WebhookUrl::resolve($ep->url);
            $ts = now()->timestamp;
            $res = Http::timeout(5)->connectTimeout(2)->withoutRedirecting()
                ->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$target['ip']}"]]])
                ->withHeaders([
                    'User-Agent' => 'TapCard-Webhooks/1.0',
                    'X-TapCard-Event' => $d->type,
                    'X-TapCard-Delivery' => $d->public_id,
                    'X-TapCard-Timestamp' => (string) $ts,
                    'X-TapCard-Signature' => Webhooks::signature($ep->secret, $ts, $d->body),
                ])
                ->withBody($d->body, 'application/json')
                ->post($ep->url);
            $status = $res->status();
            $response = Str::limit($res->body(), self::RESPONSE_MAX, '…');
            if (! $res->successful()) {
                $error = $res->redirect() ? 'Penerima mengalihkan (redirect) — redirect tidak diikuti.' : "Penerima membalas HTTP $status.";
            }
        } catch (InvalidWebhookUrl $e) {
            $error = $e->getMessage();
        } catch (ConnectionException $e) {
            $error = 'Tidak bisa terhubung: '.Str::limit($e->getMessage(), 200);
        } catch (Throwable $e) {
            $error = 'Galat: '.Str::limit($e->getMessage(), 200);
        }
        $ms = (int) round((microtime(true) - $started) * 1000);
        $base = ['attempts' => $attempt, 'http_status' => $status, 'duration_ms' => $ms, 'response_body' => $response];

        $ping = $d->type === 'ping'; // uji kirim tidak memengaruhi kesehatan & penonaktifan otomatis
        if ($error === null) {
            $d->update($base + ['status' => 'success', 'error' => null, 'next_retry_at' => null, 'delivered_at' => now()]);
            if (! $ping) {
                $ep->forceFill(['failure_count' => 0, 'last_success_at' => now()])->save();
            }

            return null;
        }

        if (! $ping) {
            $ep->forceFill(['failure_count' => $ep->failure_count + 1, 'last_failure_at' => now()])->save();
            if ($ep->is_active && $ep->failure_count >= WebhookEndpoint::DISABLE_AFTER) {
                $this->disable($ep);
            }
        }
        $delay = $retry && $ep->is_active && $attempt < self::MAX_ATTEMPTS ? Webhooks::BACKOFF[$attempt - 1] : null;
        $d->update($base + ['status' => $delay ? 'retrying' : 'failed', 'error' => $error, 'next_retry_at' => $delay ? now()->addSeconds($delay) : null]);

        return $delay;
    }

    private function disable(WebhookEndpoint $ep): void
    {
        $ep->forceFill(['is_active' => false, 'disabled_at' => now(),
            'disabled_reason' => 'Gagal '.WebhookEndpoint::DISABLE_AFTER.' kali berturut-turut.'])->save();
        Webhooks::forget($ep->tenant_id);
        $tenant = Tenant::find($ep->tenant_id);
        $admin = Role::system('admin')->id;
        $users = TenantMember::acrossTenants()->where('tenant_id', $ep->tenant_id)->where('status', 'active')
            ->where(fn ($q) => $q->where('role_id', $admin)->orWhere('user_id', $tenant?->owner_user_id))->pluck('user_id')->unique();
        foreach ($users as $uid) {
            Notify::user($uid, $ep->tenant_id, 'webhook_disabled', ['url' => $ep->url, 'endpoint' => $ep->public_id, 'failures' => WebhookEndpoint::DISABLE_AFTER]);
        }
    }
}
