<?php

namespace App\Http\Controllers\Workspace;

use App\Enums\TenantType;
use App\Exceptions\ApiError;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Audit;
use App\Support\Http\Table;
use App\Support\Webhooks\InvalidWebhookUrl;
use App\Support\Webhooks\WebhookEvents;
use App\Support\Webhooks\WebhookPayload;
use App\Support\Webhooks\Webhooks;
use App\Support\Webhooks\WebhookSender;
use App\Support\Webhooks\WebhookUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Webhook keluar workspace perusahaan (izin `integrations.manage`, hanya paket dengan fitur `webhooks`):
 * endpoint, rahasia tanda tangan (tampil sekali), uji kirim, log pengiriman, kirim ulang, dan katalog event.
 */
class WebhookController extends WorkspaceController
{
    private function gate(): void
    {
        abort_if($this->tenant()->type === TenantType::Personal, 404);
        $this->access()->authorize('integrations.manage');
    }

    /** GET /api/webhooks */
    public function index(): JsonResponse
    {
        $this->gate();
        $endpoints = WebhookEndpoint::orderBy('created_at')->get();
        // tingkat sukses 7 hari: satu query teragregasi untuk semua endpoint (selesai = sukses + gagal, uji kirim tidak dihitung)
        $stats = WebhookDelivery::where('created_at', '>=', now()->subDays(7))->where('type', '!=', 'ping')
            ->whereIn('status', ['success', 'failed'])->groupBy('endpoint_id')
            ->select('endpoint_id', DB::raw("count(*) filter (where status = 'success') as ok"), DB::raw('count(*) as total'))
            ->get()->keyBy('endpoint_id');

        return response()->json(['data' => $endpoints->map(fn ($e) => $this->present($e, $stats->get($e->id))),
            'meta' => ['max' => WebhookEndpoint::MAX_PER_WORKSPACE]]);
    }

    /** GET /api/webhooks/catalog — event + contoh payload untuk dokumentasi di halaman. */
    public function catalog(): JsonResponse
    {
        $this->gate();
        $t = $this->tenant();
        $sample = fn (string $type, array $data) => WebhookPayload::envelope('evt_01JD0EVENT0000000000000000', $type, $t, $data, '2026-11-20T09:15:00+07:00');

        return response()->json(['data' => [
            'events' => collect(WebhookEvents::all())->map(fn ($e, $key) => [
                'key' => $key, 'group' => $e['group'], 'label' => $e['label'], 'description' => $e['description'],
                'volume' => $e['volume'] ?? false, 'sample' => $sample($key, $e['sample']),
            ])->values(),
            'ping' => $sample('ping', WebhookEvents::pingSample()),
            'retry' => Webhooks::BACKOFF,
            'disable_after' => WebhookEndpoint::DISABLE_AFTER,
        ]]);
    }

    /** POST /api/webhooks — rahasia hanya ada di respons ini (dan saat rotasi). */
    public function store(Request $request): JsonResponse
    {
        $this->gate();
        $data = $this->validated($request);
        if (WebhookEndpoint::count() >= WebhookEndpoint::MAX_PER_WORKSPACE) {
            throw new ApiError('webhook_limit', 'Maksimal '.WebhookEndpoint::MAX_PER_WORKSPACE.' endpoint per workspace.');
        }
        $secret = Webhooks::newSecret();
        $e = WebhookEndpoint::create($data + ['secret' => $secret, 'is_active' => $data['is_active'] ?? true, 'created_by' => $this->user()->id]);
        Webhooks::forget($this->tenant()->id);
        Audit::tenant($this->tenant()->id, 'webhook.created', $e, null, ['url' => $e->url, 'events' => $e->events]);

        return response()->json(['data' => $this->present($e) + ['secret' => $secret]], 201);
    }

    /** PATCH /api/webhooks/{endpoint} — mengaktifkan kembali endpoint yang dinonaktifkan otomatis juga lewat sini. */
    public function update(Request $request, WebhookEndpoint $endpoint): JsonResponse
    {
        $this->gate();
        $data = $this->validated($request, partial: true);
        if (($data['is_active'] ?? false) && ! $endpoint->is_active) {
            $data += ['failure_count' => 0, 'disabled_at' => null, 'disabled_reason' => null];
        }
        $endpoint->update($data);
        Webhooks::forget($this->tenant()->id);
        Audit::tenant($this->tenant()->id, 'webhook.updated', $endpoint, null, array_intersect_key($data, array_flip(['url', 'events', 'is_active'])));

        return response()->json(['data' => $this->present($endpoint->fresh())]);
    }

    public function destroy(WebhookEndpoint $endpoint): JsonResponse
    {
        $this->gate();
        $endpoint->delete();
        Webhooks::forget($this->tenant()->id);
        Audit::tenant($this->tenant()->id, 'webhook.deleted', $endpoint, ['url' => $endpoint->url]);

        return response()->json(null, 204);
    }

    /** POST /api/webhooks/{endpoint}/secret — rahasia baru berlaku seketika; rahasia lama langsung tidak sah. */
    public function rotate(WebhookEndpoint $endpoint): JsonResponse
    {
        $this->gate();
        $secret = Webhooks::newSecret();
        $endpoint->update(['secret' => $secret]);
        Audit::tenant($this->tenant()->id, 'webhook.secret_rotated', $endpoint);

        return response()->json(['data' => $this->present($endpoint) + ['secret' => $secret]]);
    }

    /** POST /api/webhooks/{endpoint}/test — event `ping`, dikirim langsung (sekali, tanpa ulang) agar hasilnya tampil seketika. */
    public function test(WebhookEndpoint $endpoint, WebhookSender $sender): JsonResponse
    {
        $this->gate();
        $t = $this->tenant();
        $eventId = 'evt_'.Str::ulid();
        $d = WebhookDelivery::create(['endpoint_id' => $endpoint->id, 'event_id' => $eventId, 'type' => 'ping',
            'body' => Webhooks::encode(WebhookPayload::envelope($eventId, 'ping', $t, WebhookEvents::pingSample()))]);
        $sender->attempt($d->setRelation('endpoint', $endpoint), retry: false);

        return response()->json(['data' => $this->delivery($d->fresh(), true)]);
    }

    /** GET /api/webhooks/{endpoint}/deliveries?status=&type= */
    public function deliveries(Request $request, WebhookEndpoint $endpoint): JsonResponse
    {
        $this->gate();
        $q = WebhookDelivery::where('endpoint_id', $endpoint->id)
            ->when($request->query('status'), fn ($w, $s) => $w->where('status', $s))
            ->when($request->query('type'), fn ($w, $t) => $w->where('type', $t));

        return Table::json(Table::paginate($q, $request, ['created_at' => 'created_at', 'status' => 'status', 'type' => 'type'], '-created_at', [], 25),
            fn (WebhookDelivery $d) => $this->delivery($d), $request);
    }

    /** GET /api/webhook-deliveries/{delivery} — isi permintaan & balasan lengkap. */
    public function showDelivery(WebhookDelivery $delivery): JsonResponse
    {
        $this->gate();

        return response()->json(['data' => $this->delivery($delivery, true)]);
    }

    /** POST /api/webhook-deliveries/{delivery}/redeliver — delivery baru, isi & id event sama (penerima bisa dedup lewat `id`). */
    public function redeliver(WebhookDelivery $delivery): JsonResponse
    {
        $this->gate();
        $ep = WebhookEndpoint::findOrFail($delivery->endpoint_id);
        if (! $ep->is_active) {
            throw new ApiError('webhook_inactive', 'Aktifkan endpoint ini dulu sebelum kirim ulang.');
        }
        $copy = WebhookDelivery::create(['endpoint_id' => $ep->id, 'event_id' => $delivery->event_id, 'type' => $delivery->type, 'body' => $delivery->body]);
        Webhooks::queue($copy);

        return response()->json(['data' => $this->delivery($copy->fresh())], 202);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'url' => [$req, 'string', 'max:500'],
            'description' => ['sometimes', 'nullable', 'string', 'max:120'],
            'events' => [$req, 'array', 'min:1'],
            'events.*' => ['string', Rule::in(WebhookEvents::keys())],
            'is_active' => ['sometimes', 'boolean'],
        ], ['events.min' => 'Pilih minimal satu event.', 'events.required' => 'Pilih minimal satu event.', 'events.*.in' => 'Event tidak dikenal.']);
        if (isset($data['url'])) {
            $data['url'] = trim($data['url']);
            try {
                WebhookUrl::resolve($data['url']);
            } catch (InvalidWebhookUrl $e) {
                throw new ApiError('validation_failed', $e->getMessage(), 422, ['fields' => ['url' => [$e->getMessage()]]]);
            }
        }
        if (isset($data['events'])) {
            $data['events'] = array_values(array_unique($data['events']));
        }

        return $data;
    }

    private function present(WebhookEndpoint $e, $stat = null): array
    {
        return [
            'id' => $e->public_id, 'url' => $e->url, 'description' => $e->description, 'events' => $e->events ?? [],
            'is_active' => $e->is_active, 'failure_count' => $e->failure_count,
            'disabled_at' => $e->disabled_at?->toIso8601String(), 'disabled_reason' => $e->disabled_reason,
            'last_success_at' => $e->last_success_at?->toIso8601String(), 'last_failure_at' => $e->last_failure_at?->toIso8601String(),
            'success_rate_7d' => $stat && $stat->total ? round($stat->ok / $stat->total * 100, 1) : null,
            'deliveries_7d' => (int) ($stat->total ?? 0),
            'secret_hint' => 'whsec_…'.substr($e->secret, -4),
            'created_at' => $e->created_at?->toIso8601String(),
        ];
    }

    private function delivery(WebhookDelivery $d, bool $full = false): array
    {
        return [
            'id' => $d->public_id, 'event_id' => $d->event_id, 'type' => $d->type, 'status' => $d->status, 'attempts' => $d->attempts,
            'http_status' => $d->http_status, 'duration_ms' => $d->duration_ms, 'error' => $d->error,
            'next_retry_at' => $d->next_retry_at?->toIso8601String(), 'delivered_at' => $d->delivered_at?->toIso8601String(),
            'created_at' => $d->created_at?->toIso8601String(),
        ] + ($full ? ['request_body' => $d->body, 'response_body' => $d->response_body] : []);
    }
}
