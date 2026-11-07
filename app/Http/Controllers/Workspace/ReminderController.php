<?php

namespace App\Http\Controllers\Workspace;

use App\Actions\Leads\Followups;
use App\Http\Resources\Present;
use App\Models\Connection;
use App\Models\LeadReminder;
use App\Support\Http\Table;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** LD-05 — pengingat follow-up: daftar "Terlambat / Hari ini / Mendatang", tambah, selesai, ubah jadwal. */
class ReminderController extends WorkspaceController
{
    public function index(Request $request): JsonResponse
    {
        $this->access()->authorize('leads.view.*');
        $request->validate(['due' => ['nullable', Rule::in(['overdue', 'today', 'upcoming', 'done'])]]);
        $tz = $this->user()->timezone ?: 'Asia/Jakarta';
        $q = LeadReminder::with(['connection.stage'])->where('user_id', $this->user()->id)
            ->whereHas('connection', fn ($c) => $c->whereNull('merged_into_id'));
        match ($request->query('due')) {
            'overdue' => $q->whereNull('done_at')->where('due_at', '<', now()),
            'today' => $q->whereNull('done_at')->whereBetween('due_at', [now(), now($tz)->endOfDay()->utc()]),
            'upcoming' => $q->whereNull('done_at')->where('due_at', '>', now($tz)->endOfDay()->utc()),
            'done' => $q->whereNotNull('done_at'),
            default => $q->whereNull('done_at'),
        };

        $counts = LeadReminder::where('user_id', $this->user()->id)->whereNull('done_at')
            ->selectRaw('count(*) filter (where due_at < ?) as overdue, count(*) filter (where due_at >= ? and due_at <= ?) as today', [now(), now(), now($tz)->endOfDay()->utc()])
            ->first();

        return Table::json(
            Table::paginate($q, $request, ['due_at' => 'due_at', 'created_at' => 'created_at'], $request->query('due') === 'done' ? '-due_at' : 'due_at', [], 20),
            fn (LeadReminder $r) => Present::reminder($r, true),
            $request,
            ['counts' => ['overdue' => (int) $counts->overdue, 'today' => (int) $counts->today]],
        );
    }

    /** POST /api/leads/{id}/reminders */
    public function store(Request $request, Connection $connection): JsonResponse
    {
        $this->editableLead($connection);
        $data = $request->validate([
            'due_at' => ['required', 'date', 'after:-1 minute'],
            'note' => ['nullable', 'string', 'max:200'],
        ], ['due_at.after' => 'Pilih waktu yang akan datang.']);
        $r = LeadReminder::create([
            'connection_id' => $connection->id, 'user_id' => $this->user()->id,
            'due_at' => $data['due_at'], 'note' => $data['note'] ?? null,
        ]);
        Followups::sync($connection);

        return response()->json(['data' => Present::reminder($r)], 201);
    }

    /** PATCH /api/reminders/{id} — `done: true|false`, `due_at` (tunda), `note`. */
    public function update(Request $request, LeadReminder $reminder): JsonResponse
    {
        $this->mine($reminder);
        $data = $request->validate([
            'done' => ['sometimes', 'boolean'],
            'due_at' => ['sometimes', 'date'],
            'note' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);
        if (array_key_exists('done', $data)) {
            $reminder->done_at = $data['done'] ? now() : null;
        }
        if (isset($data['due_at'])) {
            $reminder->fill(['due_at' => $data['due_at'], 'notified_at' => null]); // ditunda → diingatkan lagi
        }
        if (array_key_exists('note', $data)) {
            $reminder->note = $data['note'];
        }
        $reminder->save();
        Followups::sync($reminder->connection);

        return response()->json(['data' => Present::reminder($reminder->load('connection.stage'), true)]);
    }

    public function destroy(LeadReminder $reminder): JsonResponse
    {
        $this->mine($reminder);
        $reminder->delete();
        Followups::sync($reminder->connection);

        return response()->json(null, 204);
    }

    private function mine(LeadReminder $r): void
    {
        abort_unless($r->user_id === $this->user()->id, 404);
    }

    private function editableLead(Connection $c): void
    {
        $visible = Connection::whereKey($c->id)->visibleTo($this->user(), $this->access()->context())->exists();
        abort_unless($visible, 404);
        $this->access()->authorize('leads.edit.*');
    }
}
