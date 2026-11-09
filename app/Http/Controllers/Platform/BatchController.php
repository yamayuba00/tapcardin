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
use App\Http\Controllers\Marketing\ShowcaseController;
use App\Models\Batch;
use App\Models\Card;
use App\Models\Design;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit;
use App\Support\Http\Table;
use App\Support\Qr\QrEncoder;
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
        $company = fn ($q) => $q->whereIn('tenant_id', Tenant::where('type', '!=', TenantType::Personal->value)->select('id'));
        $batches = Batch::with(['holder', 'creator', 'tenant'])->withCount([
            'cards as encoded_count' => fn ($q) => $q->whereNotNull('encoded_at'),
            'cards as qc_count' => fn ($q) => $q->whereNotNull('qc_passed_at'),
        ])->when($request->query('status'), fn ($w, $s) => $w->where('status', $s))
            // tab jalur: Jual langsung · Custom · Perusahaan (lihat Batch::channel)
            ->when($request->query('channel') === 'direct', fn ($w) => $w->where('uniform', true))
            ->when($request->query('channel') === 'company', fn ($w) => $w->where('uniform', false)->where($company))
            // tenant_id NULL (stok/pilot) termasuk Custom — NOT IN saja akan membuangnya (NULL)
            ->when($request->query('channel') === 'custom', fn ($w) => $w->where('uniform', false)
                ->where(fn ($q) => $q->whereNull('tenant_id')->orWhereNotIn('tenant_id', Tenant::where('type', '!=', TenantType::Personal->value)->select('id'))));
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
            'design' => ['nullable', 'string', 'size:26'],
        ]);
        $holder = null;
        if (! empty($data['holder_email'])) {
            $holder = User::whereRaw('lower(email) = ?', [strtolower($data['holder_email'])])->first()
                ?? throw ValidationException::withMessages(['holder_email' => 'Pengguna dengan email ini belum terdaftar. Minta mereka masuk sekali ke TapCard.']);
        }
        $design = ! empty($data['design']) ? self::myDesign($data['design']) : null;
        $batch = $create->handle(ChipType::from($data['chip_type']), (int) $data['quantity'], $holder, $data['vendor'] ?? null, uniform: (bool) ($data['uniform'] ?? false), design: $design);

        return $this->ok(self::summary($batch->load('holder')), 201);
    }

    /**
     * GET /api/batches/designs — desain kartu milik petugas ini (dibuat di Design Studio app.) untuk stok jual langsung,
     * plus data contoh & QR untuk pratinjau.
     */
    public function designs(): JsonResponse
    {
        $this->can('production.manage');
        $qr = QrEncoder::encode(config('tapcard.resolver_url').'/c/XXXXXXXXXX?src=qr');

        return $this->ok([
            'designs' => Design::withoutGlobalScopes()->with('brandKit')->where('user_id', auth()->id())->where('kind', 'card')
                ->where('is_template', false)->latest('updated_at')->limit(50)->get()->map(fn (Design $d) => self::presentDesign($d))->values(),
            'vars' => ShowcaseController::PERSONA,
            'qr' => ['modules' => $qr->size, 'path' => SvgRenderer::path($qr)],
            'studio_url' => rtrim((string) config('app.url'), '/').'/designs',
        ]);
    }

    /** PATCH /api/batches/{id}/design {design|null} — ganti / lepas desain batch kapan saja. */
    public function setDesign(Request $request, Batch $batch): JsonResponse
    {
        $this->can('production.manage');
        $data = $request->validate(['design' => ['nullable', 'string', 'size:26']]);
        $design = $data['design'] ? self::myDesign($data['design']) : null;
        $batch->update(['design_id' => $design?->id]);
        Audit::platform('batch.design_changed', $batch, null, ['design' => $design?->public_id]);

        return $this->show($batch->fresh());
    }

    /** Hanya desain kartu milik petugas yang sedang masuk. */
    private static function myDesign(string $id): Design
    {
        return Design::withoutGlobalScopes()->where('public_id', $id)->where('user_id', auth()->id())->where('kind', 'card')->first()
            ?? throw ValidationException::withMessages(['design' => 'Desain tidak ditemukan di akun Anda. Buat dulu di Design Studio.']);
    }

    public static function presentDesign(Design $d): array
    {
        return ['id' => $d->public_id, 'name' => $d->name, 'orientation' => $d->orientation, 'front' => $d->front_json, 'back' => $d->back_json,
            'kit' => $d->brandKit?->toKit(), 'studio_url' => rtrim((string) config('app.url'), '/').'/designs/'.$d->public_id,
            'per_card' => self::perCardBits($d)];
    }

    /**
     * Elemen yang berbeda tiap kartu (teks variabel {{…}}, QR, kode pendek). Stok jual langsung dicetak SAMA semua,
     * jadi elemen ini harus dihapus dari desainnya — ditampilkan sebagai peringatan, tidak memblokir.
     *
     * @return list<string>
     */
    private static function perCardBits(Design $d): array
    {
        $layers = [...($d->front_json['layers'] ?? []), ...($d->back_json['layers'] ?? [])];
        $types = array_column($layers, 'type');

        return array_values(array_filter([
            collect($layers)->contains(fn ($l) => ($l['type'] ?? null) === 'text' && str_contains((string) ($l['text'] ?? ''), '{{')) ? 'nama / jabatan otomatis' : null,
            in_array('qr', $types, true) ? 'QR unik' : null,
            in_array('shortcode', $types, true) ? 'kode kartu' : null,
        ]));
    }

    public function show(Batch $batch): JsonResponse
    {
        $this->can('production.manage');
        $batch->load(['holder', 'creator', 'design.brandKit']);
        $counts = $batch->cards()->getQuery()->selectRaw('count(*) as tokens, count(encoded_at) as encoded, count(qc_passed_at) as qc, count(*) filter (where status <> ?) as shipped', [CardStatus::Manufactured->value])->first();

        return $this->ok(self::summary($batch) + [
            'vendor' => $batch->vendor,
            'has_bundle' => (bool) $batch->print_bundle_path,
            'next_statuses' => array_map(fn ($s) => ['value' => $s->value, 'label' => $s->label()], $batch->status->allowedNext()),
            'counts' => array_map('intval', $counts->only(['tokens', 'encoded', 'qc', 'shipped'])),
            'design' => $batch->design ? self::presentDesign($batch->design) : null,
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
            'channel' => $b->channel(),
            'channel_label' => Batch::CHANNELS[$b->channel()],
            'tenant' => $b->tenant && $b->channel() === 'company' ? $b->tenant->name : null,
            'holder' => $b->holder ? ['id' => $b->holder->public_id, 'email' => $b->holder->email] : null,
            'created_at' => self::dt($b->created_at),
        ];
    }
}
