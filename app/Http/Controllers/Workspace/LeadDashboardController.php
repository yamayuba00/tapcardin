<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Resources\Present;
use App\Models\Connection;
use App\Models\Event;
use App\Models\LeadStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Dashboard lead yang bisa diatur tiap pengguna: pilih widget, urutan, dan lebar; periode 7/30/90 hari.
 * Semua angka dihitung di server (agregasi SQL) sesuai cakupan izin lead pengguna (visibleTo).
 * Tata letak disimpan di `users.preferences.lead_dashboard`.
 */
class LeadDashboardController extends WorkspaceController
{
    /** id => [judul, jenis, lebar bawaan 1|2] */
    public const WIDGETS = [
        'kpi_total' => ['Lead aktif', 'kpi', 1],
        'kpi_new' => ['Lead baru', 'kpi', 1],
        'kpi_won_rate' => ['Rasio menang', 'kpi', 1],
        'kpi_followup' => ['Follow-up', 'kpi', 1],
        'kpi_value' => ['Nilai pipeline', 'kpi', 1],
        'new_by_day' => ['Lead baru per hari', 'series', 2],
        'by_stage' => ['Pipeline per tahap', 'bars', 1],
        'by_source' => ['Sumber lead', 'bars', 1],
        'by_event' => ['Lead per event', 'bars', 1],
        'lost_reasons' => ['Alasan kalah', 'bars', 1],
        'top_tags' => ['Tag teratas', 'bars', 1],
        'overdue_list' => ['Follow-up terlambat', 'list', 1],
        'recent_list' => ['Lead terbaru', 'list', 1],
    ];

    public const DEFAULT_LAYOUT = [
        ['id' => 'kpi_total', 'size' => 1], ['id' => 'kpi_new', 'size' => 1], ['id' => 'kpi_won_rate', 'size' => 1], ['id' => 'kpi_followup', 'size' => 1],
        ['id' => 'new_by_day', 'size' => 2], ['id' => 'by_stage', 'size' => 1], ['id' => 'by_source', 'size' => 1],
        ['id' => 'overdue_list', 'size' => 1], ['id' => 'recent_list', 'size' => 1],
    ];

    public function show(Request $request): JsonResponse
    {
        $this->access()->authorize('leads.view.*');
        $request->validate(['days' => ['nullable', Rule::in([7, 30, 90])]]);
        $prefs = $this->user()->preferences['lead_dashboard'] ?? [];
        $layout = self::cleanLayout($prefs['widgets'] ?? self::DEFAULT_LAYOUT);
        $days = (int) ($request->query('days') ?? $prefs['days'] ?? 30);

        $tz = $this->user()->timezone ?: 'Asia/Jakarta';
        $from = now($tz)->subDays($days - 1)->startOfDay();
        $prevFrom = (clone $from)->subDays($days);
        $data = [];
        foreach (array_unique(array_column($layout, 'id')) as $id) {
            $data[$id] = $this->widget($id, $from, $prevFrom, $tz, $days);
        }

        return response()->json(['data' => [
            'days' => $days, 'layout' => $layout, 'widgets' => $data,
            'catalog' => collect(self::WIDGETS)->map(fn ($w, $id) => ['id' => $id, 'title' => $w[0], 'kind' => $w[1], 'size' => $w[2]])->values(),
            'default_layout' => self::DEFAULT_LAYOUT,
        ]]);
    }

    /** PUT /api/leads/dashboard/layout {widgets: [{id, size}], days} */
    public function saveLayout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'widgets' => ['required', 'array', 'max:24'],
            'widgets.*.id' => ['required', Rule::in(array_keys(self::WIDGETS))],
            'widgets.*.size' => ['required', Rule::in([1, 2])],
            'days' => ['required', Rule::in([7, 30, 90])],
        ]);
        $u = $this->user();
        $u->update(['preferences' => array_merge($u->preferences ?? [], ['lead_dashboard' => [
            'widgets' => self::cleanLayout($data['widgets']), 'days' => (int) $data['days'],
        ]])]);

        return $this->show($request->merge(['days' => $data['days']]));
    }

    private static function cleanLayout(array $widgets): array
    {
        return collect($widgets)->filter(fn ($w) => isset(self::WIDGETS[$w['id'] ?? '']))
            ->unique('id')->map(fn ($w) => ['id' => $w['id'], 'size' => (int) ($w['size'] ?? 1) === 2 ? 2 : 1])->values()->all();
    }

    private function leads(): Builder
    {
        return Connection::query()->whereNull('merged_into_id')->visibleTo($this->user(), $this->access()->context());
    }

    private function widget(string $id, $from, $prevFrom, string $tz, int $days): array
    {
        $period = fn () => $this->leads()->where('connections.created_at', '>=', $from);
        $bars = fn (Builder $q, string $label) => $q->selectRaw("$label as label, count(*) as value")
            ->groupByRaw('1')->orderByDesc('value')->limit(8)->get()
            ->map(fn ($r) => ['label' => (string) $r->label, 'value' => (int) $r->value])->values()->all();

        return match ($id) {
            'kpi_total' => ['value' => $this->leads()->whereNull('closed_at')->count(), 'hint' => $this->leads()->count().' total termasuk selesai'],
            'kpi_new' => (function () use ($period, $from, $prevFrom) {
                $now = $period()->count();
                $prev = $this->leads()->whereBetween('connections.created_at', [$prevFrom, $from])->count();

                return ['value' => $now, 'previous' => $prev, 'delta' => $prev ? (int) round(($now - $prev) / $prev * 100) : null];
            })(),
            'kpi_won_rate' => (function () use ($from) {
                $r = $this->leads()->where('closed_at', '>=', $from)
                    ->selectRaw("count(*) filter (where outcome = 'won') as won, count(*) filter (where outcome = 'lost') as lost")->first();
                $closed = $r->won + $r->lost;

                return ['value' => $closed ? (int) round($r->won / $closed * 100) : null, 'won' => (int) $r->won, 'lost' => (int) $r->lost];
            })(),
            'kpi_followup' => [
                'overdue' => $this->leads()->whereNull('closed_at')->where('next_followup_at', '<', now())->count(),
                'today' => $this->leads()->whereNull('closed_at')->whereBetween('next_followup_at', [now(), now($tz)->endOfDay()])->count(),
            ],
            'kpi_value' => [
                'value' => (int) $this->leads()->whereNull('closed_at')->sum('value_amount'),
                'won' => (int) $this->leads()->where('outcome', 'won')->where('closed_at', '>=', $from)->sum('value_amount'),
            ],
            'new_by_day' => (function () use ($period, $tz, $days) {
                $rows = $period()->selectRaw('(connections.created_at at time zone ?)::date as d, count(*) as n', [$tz])
                    ->groupBy('d')->pluck('n', 'd');
                $out = [];
                for ($i = $days - 1; $i >= 0; $i--) {
                    $d = now($tz)->subDays($i)->toDateString();
                    $out[] = ['date' => $d, 'value' => (int) ($rows[$d] ?? 0)];
                }

                return ['series' => $out];
            })(),
            'by_stage' => (function () {
                $counts = $this->leads()->whereNull('closed_at')->selectRaw('stage_id, count(*) as n')->groupBy('stage_id')->pluck('n', 'stage_id');

                return ['bars' => LeadStage::orderBy('sort')->where('kind', 'open')->get()
                    ->map(fn ($s) => ['label' => $s->name, 'value' => (int) ($counts[$s->id] ?? 0)])->values()->all()];
            })(),
            'by_source' => ['bars' => collect($bars($period(), 'source'))
                ->map(fn ($b) => ['label' => self::SOURCE_LABELS[$b['label']] ?? $b['label'], 'value' => $b['value']])->all()],
            'by_event' => (function () use ($period) {
                // tanpa join: kolom `team_id` events akan bentrok dengan filter cakupan lead tim
                $counts = $period()->whereNotNull('event_id')->selectRaw('event_id, count(*) as n')->groupBy('event_id')
                    ->orderByDesc('n')->limit(8)->pluck('n', 'event_id');
                $names = Event::whereIn('id', $counts->keys())->pluck('name', 'id');

                return ['bars' => $counts->map(fn ($n, $id) => ['label' => $names[$id] ?? '—', 'value' => (int) $n])->values()->all()];
            })(),
            'lost_reasons' => ['bars' => $bars($this->leads()->where('outcome', 'lost')->where('closed_at', '>=', $from)->whereNotNull('lost_reason'), 'lost_reason')],
            'top_tags' => ['bars' => DB::query()->fromSub($period()->selectRaw('unnest(tags) as tag'), 't')
                ->selectRaw('tag as label, count(*) as value')->groupBy('tag')->orderByDesc('value')->limit(8)->get()
                ->map(fn ($r) => ['label' => $r->label, 'value' => (int) $r->value])->all()],
            'overdue_list' => ['rows' => $this->leads()->with('stage')->whereNull('closed_at')->where('next_followup_at', '<', now())
                ->orderBy('next_followup_at')->limit(8)->get()->map(fn ($c) => Present::connection($c))->all()],
            'recent_list' => ['rows' => $this->leads()->with('stage')->latest('connections.created_at')->limit(8)->get()
                ->map(fn ($c) => Present::connection($c))->all()],
        };
    }

    private const SOURCE_LABELS = [
        'exchange_form' => 'Tukar Kontak', 'scan_tapcard' => 'Scan TapCard', 'scan_external' => 'Scan kartu lain',
        'manual' => 'Input manual', 'import' => 'Impor CSV', 'event_checkin' => 'Check-in event', 'saved_from_profile' => 'Simpan dari profil', 'bio_form' => 'Formulir bio link',
    ];
}
