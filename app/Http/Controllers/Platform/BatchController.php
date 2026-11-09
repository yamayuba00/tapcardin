<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Production\CreateBatch;
use App\Actions\Production\GenerateBatchTokens;
use App\Actions\Production\ShipBatch;
use App\Enums\BatchStatus;
use App\Enums\CardStatus;
use App\Enums\ChipType;
use App\Enums\TenantType;
use App\Exceptions\ApiError;
use App\Models\Batch;
use App\Models\Card;
use App\Models\User;
use App\Support\Audit;
use App\Support\Http\Table;
use App\Support\Qr\SvgRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** PL-06 / PR-01–PR-02 — batch produksi, bundle vendor, pengiriman. */
class BatchController extends PlatformController
{
    public function index(Request $request): JsonResponse
    {
        $this->can('production.manage');
        $batches = Batch::with(['holder', 'creator'])->withCount([
            'cards as encoded_count' => fn ($q) => $q->whereNotNull('encoded_at'),
            'cards as qc_count' => fn ($q) => $q->whereNotNull('qc_passed_at'),
        ])->when($request->query('status'), fn ($w, $s) => $w->where('status', $s));
        $sorts = ['code' => 'code', 'status' => 'status', 'quantity' => 'quantity', 'created_at' => 'created_at'];

        return Table::json(Table::paginate($batches, $request, $sorts, '-created_at', ['code', 'vendor'], 20), fn (Batch $b) => self::summary($b) + [
            'encoded_count' => $b->encoded_count, 'qc_count' => $b->qc_count,
        ], $request);
    }

    /** GET /api/batches/{id}/cards — batch bisa berisi 1.000 kartu, jadi daftar kartunya berhalaman. */
    public function cards(Request $request, Batch $batch): JsonResponse
    {
        $this->can('production.manage');
        $q = $batch->cards()->getQuery()->with(['holder', 'assignee', 'batch', 'tenant'])
            ->when($request->query('progress') === 'pending_encode', fn ($w) => $w->whereNull('encoded_at'))
            ->when($request->query('progress') === 'pending_qc', fn ($w) => $w->whereNotNull('encoded_at')->whereNull('qc_passed_at'))
            ->when($request->query('progress') === 'done', fn ($w) => $w->whereNotNull('qc_passed_at'));

        return Table::json(Table::paginate($q, $request, ['sequence' => 'sequence'] + CardController::SORTS, 'sequence', ['card_token', 'short_code'], 50),
            fn (Card $c) => CardController::summary($c) + ['encoded' => $c->encoded_at !== null, 'qc' => $c->qc_passed_at !== null,
                // lolos QC & belum dipakai → tombol Aktivasi (panel → Aktivasi kartu); kartu workspace perusahaan tidak
                'activatable' => $c->qc_passed_at !== null && in_array($c->status, [CardStatus::Manufactured, CardStatus::Unclaimed], true)
                    && ! $c->holder_user_id && (! $c->tenant_id || $c->tenant?->type === TenantType::Personal)], $request);
    }

    public function store(Request $request, CreateBatch $create): JsonResponse
    {
        $this->can('production.manage');
        $data = $request->validate([
            'chip_type' => ['required', Rule::in(['ntag213', 'ntag215', 'ntag216'])], // 424 DNA: v1.5
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'holder_email' => ['nullable', 'email'],
            'vendor' => ['nullable', 'string', 'max:80'],
            'uniform' => ['boolean'],
        ]);
        $holder = null;
        if (! empty($data['holder_email'])) {
            $holder = User::whereRaw('lower(email) = ?', [strtolower($data['holder_email'])])->first()
                ?? throw ValidationException::withMessages(['holder_email' => 'Pengguna dengan email ini belum terdaftar. Minta mereka masuk sekali ke TapCard.']);
        }
        $batch = $create->handle(ChipType::from($data['chip_type']), (int) $data['quantity'], $holder, $data['vendor'] ?? null, uniform: (bool) ($data['uniform'] ?? false));

        return $this->ok(self::summary($batch->load('holder')), 201);
    }

    public function show(Batch $batch): JsonResponse
    {
        $this->can('production.manage');
        $batch->load(['holder', 'creator']);
        $counts = $batch->cards()->getQuery()->selectRaw('count(*) as tokens, count(encoded_at) as encoded, count(qc_passed_at) as qc, count(*) filter (where status <> ?) as shipped', [CardStatus::Manufactured->value])->first();

        return $this->ok(self::summary($batch) + [
            'vendor' => $batch->vendor,
            'has_bundle' => (bool) $batch->print_bundle_path,
            'next_statuses' => array_map(fn ($s) => ['value' => $s->value, 'label' => $s->label()], $batch->status->allowedNext()),
            'counts' => array_map('intval', $counts->only(['tokens', 'encoded', 'qc', 'shipped'])),
            // pembuatan token di latar (per GenerateBatchTokens::CHUNK kartu)
            'generation' => $batch->status === BatchStatus::Draft && ($batch->tokens_started_at || $batch->tokens_error) ? [
                'running' => GenerateBatchTokens::running($batch),
                'stalled' => ! GenerateBatchTokens::running($batch) && ! $batch->tokens_error,
                'error' => $batch->tokens_error,
                'started_at' => self::dt($batch->tokens_started_at),
            ] : null,
            // pesanan asal (bila batch dibuat dari panel → Pesanan)
            'order' => ($o = $batch->orderItem?->order) ? ['id' => $o->public_id, 'number' => $o->number] : null,
        ]);
    }

    /** POST /api/batches/{id}/generate — mulai (atau lanjutkan) pembuatan token di latar; progres dibaca dari show. */
    public function generate(Batch $batch, GenerateBatchTokens $generate): JsonResponse
    {
        $this->can('production.manage');
        $generate->handle($batch);

        return $this->show($batch->fresh());
    }

    public function bundle(Batch $batch): StreamedResponse
    {
        $this->can('production.manage');
        abort_unless($batch->print_bundle_path && Storage::disk('local')->exists($batch->print_bundle_path), 404);
        Audit::platform('batch.bundle_downloaded', $batch);

        return Storage::disk('local')->download($batch->print_bundle_path, "{$batch->code}.csv");
    }

    /** Lembar QR + kode pendek per kartu (pratinjau cetak; PDF vektor via Chromium di v1). */
    public function print(Batch $batch): JsonResponse
    {
        $this->can('production.manage');

        return $this->ok([
            'code' => $batch->code,
            'cards' => $batch->cards()->orderBy('sequence')->get()->map(fn (Card $c) => [
                'sequence' => $c->sequence, 'short_code' => $c->formattedShortCode(), 'svg' => SvgRenderer::forUrl($c->qrUrl()),
            ]),
        ]);
    }

    public function status(Request $request, Batch $batch): JsonResponse
    {
        $this->can('production.manage');
        $to = BatchStatus::from($request->validate(['status' => ['required', Rule::in(['sent_to_print', 'printed', 'cancelled'])]])['status']);
        if (! $batch->status->canTransitionTo($to)) {
            throw new ApiError('invalid_transition', "Batch {$batch->status->label()} tidak bisa diubah ke {$to->label()}.");
        }
        $before = $batch->status->value;
        $batch->update(['status' => $to]);
        Audit::platform('batch.status_changed', $batch, ['status' => $before], ['status' => $to->value]);

        return $this->show($batch);
    }

    public function ship(Request $request, Batch $batch, ShipBatch $ship): JsonResponse
    {
        $this->can('production.manage');
        $data = $request->validate(['tracking_number' => ['nullable', 'string', 'max:60']]);
        $ship->handle($batch, $data['tracking_number'] ?? null);

        return $this->show($batch->fresh());
    }

    public static function summary(Batch $b): array
    {
        return [
            'id' => $b->public_id,
            'code' => $b->code,
            'chip_type' => $b->chip_type->value,
            'quantity' => $b->quantity,
            'status' => $b->status->value,
            'status_label' => $b->status->label(),
            'uniform' => (bool) $b->uniform,
            'holder' => $b->holder ? ['id' => $b->holder->public_id, 'email' => $b->holder->email] : null,
            'created_at' => self::dt($b->created_at),
        ];
    }
}
