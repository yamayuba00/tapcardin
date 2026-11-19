<?php

namespace App\Support\Webhooks;

use App\Authz\PlanFeatures;
use App\Jobs\DeliverWebhook;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Titik tunggal pengiriman event webhook. Jalur panas (tap, lead) hanya membaca cache daftar endpoint aktif;
 * baris pengiriman dibuat di transaksi pemanggil dan job baru dikirim setelah commit (rollback = tidak terkirim).
 */
final class Webhooks
{
    public const QUEUE = 'webhooks';

    /** Jeda percobaan ulang setelah gagal ke-1..6 (detik): 1m, 5m, 30m, 2j, 6j, 12j. */
    public const BACKOFF = [60, 300, 1800, 7200, 21600, 43200];

    /** @param  array|Closure(): array  $data  closure = dibangun hanya bila ada endpoint yang mendengar */
    public static function emit(?int $tenantId, string $type, array|Closure $data): void
    {
        if (! $tenantId) {
            return;
        }
        try {
            $endpoints = array_filter(self::endpoints($tenantId), fn ($events) => in_array($type, $events, true));
            if (! $endpoints) {
                return;
            }
            // savepoint: galat query di sini tidak boleh membatalkan transaksi pemanggil (Postgres membatalkan seluruhnya)
            DB::transaction(function () use ($endpoints, $tenantId, $type, $data) {
                $tenant = Tenant::find($tenantId);
                if (! $tenant || ! PlanFeatures::has($tenant, 'webhooks')) {
                    return;
                }
                $eventId = 'evt_'.Str::ulid();
                $body = self::encode(WebhookPayload::envelope($eventId, $type, $tenant, $data instanceof Closure ? $data() : $data));
                foreach (array_keys($endpoints) as $endpointId) {
                    self::queue(WebhookDelivery::create(['tenant_id' => $tenantId, 'endpoint_id' => $endpointId, 'event_id' => $eventId, 'type' => $type, 'body' => $body]));
                }
            });
        } catch (Throwable $e) {
            report($e); // webhook tidak boleh menggagalkan aksi utama (tap, lead, kartu)
        }
    }

    public static function queue(WebhookDelivery $d): void
    {
        DeliverWebhook::dispatch($d->id)->afterCommit();
    }

    /** @return array<int, list<string>> endpoint_id => event yang didengar (hanya endpoint aktif) */
    public static function endpoints(int $tenantId): array
    {
        return Cache::remember("webhooks:t$tenantId", 300, fn () => WebhookEndpoint::acrossTenants()->where('tenant_id', $tenantId)
            ->where('is_active', true)->get(['id', 'events'])->mapWithKeys(fn ($e) => [$e->id => $e->events ?? []])->all());
    }

    public static function forget(int $tenantId): void
    {
        Cache::forget("webhooks:t$tenantId");
    }

    public static function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** `v1=` + hex HMAC-SHA256(secret, "{timestamp}.{body}"). */
    public static function signature(string $secret, int $timestamp, string $body): string
    {
        return 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    public static function newSecret(): string
    {
        return 'whsec_'.Str::random(40);
    }
}
