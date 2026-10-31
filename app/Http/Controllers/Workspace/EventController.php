<?php

namespace App\Http\Controllers\Workspace;

use App\Models\Connection;
use App\Models\Event;
use App\Models\ProductEvent;
use App\Models\Tap;
use App\Models\TenantMember;
use App\Support\Http\Table;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * U-09 / AFD F-10 — Mode Event. Selama "Saya sedang di event ini" aktif, setiap tap & koneksi
 * diberi `event_id` (RecordTap, Tukar Kontak, Mode Reader) dan profil boleh menampilkan "Bertemu di {Event}".
 */
class EventController extends WorkspaceController
{
    public function index(Request $request): JsonResponse
    {
        $this->access()->authorize('events.manage.*');
        $q = Event::query()->withCount('connections')
            ->when($request->query('when') === 'upcoming', fn ($w) => $w->where('ends_at', '>=', now()))
            ->when($request->query('when') === 'past', fn ($w) => $w->where('ends_at', '<', now()));
        $active = $this->member()?->active_event_until?->isFuture() ? $this->member()->active_event_id : null;

        return Table::json(
            Table::paginate($q, $request, ['name' => 'name', 'starts_at' => 'starts_at', 'connections' => 'connections_count'], '-starts_at', ['name', 'location'], 10),
            fn (Event $e) => self::present($e, $active),
            $request,
            ['active' => $active ? self::present(Event::find($active), $active) : null],
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->access()->authorize('events.manage.*');
        $event = Event::create($this->validated($request) + ['created_by' => $this->user()->id]);
        if ($request->boolean('activate')) {
            $this->setActive($event);
        }

        return response()->json(['data' => self::present($event->loadCount('connections'), $request->boolean('activate') ? $event->id : null)], 201);
    }

    /** Ringkasan setelah/selama event (AFD F-10 langkah 4). */
    public function show(Event $event): JsonResponse
    {
        $this->access()->authorize('events.manage.*');
        $taps = Tap::where('event_id', $event->id)
            ->selectRaw('count(*) as taps, count(distinct visitor_hash) as visitors, count(*) filter (where saved_contact) as saved')->first();
        $leads = Connection::where('event_id', $event->id)->whereNull('merged_into_id');
        $byStage = (clone $leads)->join('lead_stages', 'lead_stages.id', '=', 'connections.stage_id')
            ->selectRaw('lead_stages.name as label, count(*) as value')->groupBy('lead_stages.name', 'lead_stages.sort')->orderBy('lead_stages.sort')->get();
        $active = $this->member()?->active_event_until?->isFuture() ? $this->member()->active_event_id : null;

        return response()->json(['data' => self::present($event->loadCount('connections'), $active) + [
            'summary' => [
                'taps' => (int) $taps->taps, 'visitors' => (int) $taps->visitors, 'saved' => (int) $taps->saved,
                'connections' => (clone $leads)->count(),
                // rasio simpan = pengunjung yang menyimpan kontak / pengunjung unik
                'save_rate' => $taps->visitors ? round($taps->saved / $taps->visitors * 100) : null,
            ],
            'by_stage' => $byStage->map(fn ($r) => ['label' => $r->label, 'value' => (int) $r->value]),
        ]]);
    }

    public function update(Request $request, Event $event): JsonResponse
    {
        $this->access()->authorize('events.manage.*');
        $event->update($this->validated($request, partial: true));

        return $this->show($event);
    }

    public function destroy(Event $event): JsonResponse
    {
        $this->access()->authorize('events.manage.*');
        // tap & koneksi tetap ada; hanya penanda event-nya yang dilepas (FK nullOnDelete)
        TenantMember::where('active_event_id', $event->id)->update(['active_event_id' => null, 'active_event_until' => null]);
        $event->delete();

        return response()->json(null, 204);
    }

    /** POST /api/events/{id}/activate — "Saya sedang di event ini" sampai `ends_at`. */
    public function activate(Event $event): JsonResponse
    {
        $this->access()->authorize('events.manage.*');
        abort_if($event->ends_at->isPast(), 422, 'Event sudah selesai.');
        $this->setActive($event);

        return $this->show($event);
    }

    /** DELETE /api/events/active */
    public function deactivate(): JsonResponse
    {
        $this->member()?->update(['active_event_id' => null, 'active_event_until' => null]);

        return response()->json(['data' => ['active' => null]]);
    }

    private function setActive(Event $event): void
    {
        $this->member()?->update(['active_event_id' => $event->id, 'active_event_until' => $event->ends_at]);
        ProductEvent::record('event_mode_on', $this->tenant()->id, $this->user()->id, ['event' => $event->public_id]);
    }

    private function member(): ?TenantMember
    {
        return TenantMember::where('user_id', $this->user()->id)->where('status', 'active')->first();
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $s = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$s, 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:160'],
            'starts_at' => [$s, 'date'],
            'ends_at' => [$s, 'date', 'after:starts_at'],
            'show_on_profile' => ['sometimes', 'boolean'],
        ], ['ends_at.after' => 'Waktu selesai harus setelah waktu mulai.']);
        if (array_key_exists('show_on_profile', $data)) {
            $data['settings'] = ['show_on_profile' => $data['show_on_profile']];
            unset($data['show_on_profile']);
        }

        return $data;
    }

    public static function present(Event $e, ?int $activeId = null): array
    {
        return [
            'id' => $e->public_id,
            'name' => $e->name,
            'location' => $e->location,
            'starts_at' => $e->starts_at->toIso8601String(),
            'ends_at' => $e->ends_at->toIso8601String(),
            'show_on_profile' => (bool) ($e->settings['show_on_profile'] ?? false),
            'connections_count' => $e->connections_count ?? null,
            'is_active' => $activeId === $e->id,
            'status' => $e->ends_at->isPast() ? 'past' : ($e->starts_at->isFuture() ? 'upcoming' : 'live'),
        ];
    }
}
