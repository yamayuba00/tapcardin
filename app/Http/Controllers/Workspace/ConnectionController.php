<?php

namespace App\Http\Controllers\Workspace;

use App\Actions\Leads\CreateConnection;
use App\Actions\Leads\Followups;
use App\Enums\ActivityType;
use App\Enums\LeadSource;
use App\Enums\StageKind;
use App\Http\Resources\Present;
use App\Models\Connection;
use App\Models\LeadActivity;
use App\Models\LeadStage;
use App\Support\Audit;
use App\Support\Http\Table;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** U-06/U-07 — Koneksi (Personal) / Lead (Perusahaan). Cakupan izin lewat scopeVisibleTo (TRD §5.3). */
class ConnectionController extends WorkspaceController
{
    public function stages(): JsonResponse
    {
        return response()->json(['data' => LeadStage::orderBy('sort')->get()->map(fn ($s) => Present::stage($s))]);
    }

    public function list(Request $request): JsonResponse
    {
        $this->access()->authorize('leads.view.*');
        $q = $this->query($request)->with(['stage', 'assignee', 'event']);

        return Table::json(Table::paginate($q, $request, self::SORTS, '-created_at'), fn ($c) => Present::connection($c), $request);
    }

    public function detail(Connection $connection): JsonResponse
    {
        $this->visible($connection);

        return response()->json(['data' => Present::connection($connection->load(['stage', 'assignee', 'card', 'event', 'activities.user']), true)]);
    }

    public function store(Request $request, CreateConnection $create): JsonResponse
    {
        $this->access()->authorize('leads.edit.*');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:32'],
            'company' => ['nullable', 'string', 'max:120'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        ['connection' => $c, 'duplicate' => $dup] = $create->handle($data, LeadSource::Manual, $this->user()->id);

        return response()->json(['data' => Present::connection($c->load('stage')), 'meta' => ['duplicate' => $dup]], $dup ? 200 : 201);
    }

    public function update(Request $request, Connection $connection): JsonResponse
    {
        $this->editable($connection);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:190'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'company' => ['sometimes', 'nullable', 'string', 'max:120'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'tags' => ['sometimes', 'array', 'max:20'],
            'tags.*' => ['string', 'max:30'],
            'value_amount' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);
        if (array_key_exists('email', $data)) {
            $data['email_norm'] = $data['email'] ? strtolower($data['email']) : null;
        }
        if (array_key_exists('phone', $data)) {
            $data['phone_e164'] = Phone::toE164($data['phone']);
        }
        $connection->update($data);

        return $this->detail($connection);
    }

    /** POST /api/leads/{id}/stage — alasan kalah wajib (LD-03). */
    public function stage(Request $request, Connection $connection): JsonResponse
    {
        $this->editable($connection);
        $data = $request->validate([
            'stage_id' => ['required', 'string'],
            'lost_reason' => ['nullable', 'string', 'max:80'],
        ]);
        $stage = LeadStage::where('public_id', $data['stage_id'])->firstOrFail();
        if ($stage->kind === StageKind::Lost) {
            $request->validate(['lost_reason' => ['required', Rule::in($this->tenant()->setting('lost_reasons'))]],
                ['lost_reason.required' => 'Pilih alasan kalah.']);
        }
        $from = $connection->stage;
        $connection->update([
            'stage_id' => $stage->id,
            'outcome' => $stage->kind === StageKind::Open ? null : $stage->kind->value,
            'lost_reason' => $stage->kind === StageKind::Lost ? $data['lost_reason'] : null,
            'closed_at' => $stage->kind === StageKind::Open ? null : now(),
            'last_activity_at' => now(),
        ]);
        $this->log($connection, ActivityType::StageChanged, null, ['from_stage' => $from?->name, 'to_stage' => $stage->name]);
        Followups::cancelAuto($connection);

        return $this->detail($connection);
    }

    /** POST /api/leads/{id}/activities — catatan, telepon, WA, email, meeting (LD-04). */
    public function activity(Request $request, Connection $connection): JsonResponse
    {
        $this->editable($connection);
        $data = $request->validate([
            'type' => ['required', Rule::in(['note', 'call', 'whatsapp', 'email', 'meeting'])],
            'body' => ['nullable', 'string', 'max:2000', 'required_if:type,note'],
        ]);
        $this->log($connection, ActivityType::from($data['type']), $data['body'] ?? null, []);
        $connection->update([
            'last_activity_at' => now(),
            'first_activity_at' => $connection->first_activity_at ?? now(),
        ]);
        // menghubungi lead (telepon/WA/email/meeting) = follow-up terjadi; catatan saja tidak membatalkan pengingat
        if ($data['type'] !== 'note') {
            Followups::cancelAuto($connection);
        }

        return $this->detail($connection);
    }

    public function destroy(Connection $connection): JsonResponse
    {
        $this->visible($connection);
        $this->access()->authorize('leads.delete');
        $connection->delete();
        Audit::tenant($this->tenant()->id, 'lead.deleted', $connection, ['name' => $connection->name]);

        return response()->json(null, 204);
    }

    /** GET /api/leads/export.csv — di-stream sesuai cakupan izin, dicatat di audit (TRD §10.7). */
    public function export(Request $request): StreamedResponse
    {
        $this->access()->authorize('leads.export.*');
        $q = $this->query($request)->with(['stage', 'assignee']);
        Audit::tenant($this->tenant()->id, 'lead.exported', $this->tenant(), null, ['filters' => $request->only('q', 'stage')]);

        return response()->streamDownload(function () use ($q) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM agar Excel membaca UTF-8
            fputcsv($out, ['nama', 'perusahaan', 'jabatan', 'email', 'telepon', 'tahap', 'sumber', 'tag', 'catatan', 'dibuat'], escape: '');
            $q->orderBy('id')->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $c) {
                    fputcsv($out, [
                        $c->name, $c->company, $c->job_title, $c->email, $c->phone_e164 ?? $c->phone,
                        $c->stage?->name, $c->source->value, implode(', ', $c->tags), $c->note,
                        $c->created_at->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
                    ], escape: '');
                }
            });
            fclose($out);
        }, 'koneksi-'.now('Asia/Jakarta')->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    public const SORTS = [
        'name' => 'connections.name', 'company' => 'connections.company', 'created_at' => 'connections.created_at',
        'last_activity_at' => 'connections.last_activity_at', 'next_followup_at' => 'connections.next_followup_at',
        'value_amount' => 'connections.value_amount',
        'stage' => self::class.'::sortByStage',
    ];

    public static function sortByStage($q, string $dir): void
    {
        $q->orderBy(LeadStage::select('sort')->whereColumn('lead_stages.id', 'connections.stage_id'), $dir);
    }

    private function query(Request $request)
    {
        $user = $this->user();
        $q = Connection::query()->whereNull('merged_into_id')->visibleTo($user, $this->access()->context());
        if ($s = trim((string) $request->query('q'))) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $s).'%';
            $q->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('company', 'ilike', $like)
                ->orWhere('email', 'ilike', $like)->orWhere('phone_e164', 'ilike', $like));
        }
        // filter multi-pilih: nilai dipisah koma (mis. ?stage=ULID1,ULID2)
        if ($stages = self::csv($request, 'stage')) {
            $q->whereHas('stage', fn ($w) => $w->whereIn('public_id', $stages));
        }
        if ($sources = self::csv($request, 'source')) {
            $q->whereIn('source', $sources);
        }
        if ($tags = self::csv($request, 'tag')) {
            $q->where(fn ($w) => collect($tags)->each(fn ($t) => $w->orWhereRaw('? = any(tags)', [$t])));
        }
        if ($event = $request->query('event')) {
            $q->whereHas('event', fn ($w) => $w->where('public_id', $event));
        }
        // follow-up: terlambat / hari ini (zona waktu pengguna) / 7 hari ke depan / tanpa jadwal
        $tz = $this->user()->timezone ?: 'Asia/Jakarta';
        match ($request->query('due')) {
            'overdue' => $q->where('next_followup_at', '<', now()),
            'today' => $q->whereBetween('next_followup_at', [now($tz)->startOfDay(), now($tz)->endOfDay()]),
            'week' => $q->whereBetween('next_followup_at', [now(), now()->addDays(7)]),
            'none' => $q->whereNull('next_followup_at')->whereNull('closed_at'),
            default => null,
        };

        return $q;
    }

    /** @return list<string> */
    private static function csv(Request $request, string $key): array
    {
        return array_slice(array_values(array_filter(array_map('trim', explode(',', (string) $request->query($key))))), 0, 50);
    }

    /** GET /api/leads/tags — tag yang pernah dipakai (untuk pilihan filter & input tag). */
    public function tags(): JsonResponse
    {
        $this->access()->authorize('leads.view.*');
        $q = Connection::query()->whereNull('merged_into_id')->visibleTo($this->user(), $this->access()->context());
        $rows = DB::query()->fromSub($q->selectRaw('unnest(tags) as tag'), 't')
            ->selectRaw('tag, count(*) as n')->groupBy('tag')->orderByDesc('n')->limit(200)->get();

        return response()->json(['data' => $rows->map(fn ($r) => ['value' => $r->tag, 'count' => (int) $r->n])]);
    }

    private function visible(Connection $c): void
    {
        $exists = Connection::whereKey($c->id)->visibleTo($this->user(), $this->access()->context())->exists();
        abort_unless($exists, 404);
    }

    private function editable(Connection $c): void
    {
        $this->visible($c);
        $ctx = $this->access();
        $mine = $c->assignee_user_id === $this->user()->id || $c->captured_by_user_id === $this->user()->id;
        abort_unless($ctx->has('leads.edit.all') || $ctx->has('leads.edit.team') || ($mine && $ctx->has('leads.edit.own')), 403);
    }

    private function log(Connection $c, ActivityType $type, ?string $body, array $meta): void
    {
        LeadActivity::create([
            'connection_id' => $c->id,
            'user_id' => $this->user()->id,
            'type' => $type,
            'body' => $body,
            'meta' => $meta,
            'occurred_at' => now(),
        ]);
    }
}
