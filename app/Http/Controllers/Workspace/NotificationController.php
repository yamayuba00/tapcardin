<?php

namespace App\Http\Controllers\Workspace;

use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Daftar notifikasi (lonceng & halaman Notifikasi, kursor `before`), fallback polling SSE (`since`), tandai dibaca. */
class NotificationController extends WorkspaceController
{
    public function index(Request $request): JsonResponse
    {
        $limit = 30;
        $rows = $this->scoped()
            ->when($request->query('since'), fn ($q, $since) => $q->where('id', '>', (int) $since))
            ->when($request->query('before'), fn ($q, $before) => $q->where('id', '<', (int) $before))
            ->orderByDesc('id')->limit($limit + 1)->get(['id', 'type', 'data', 'read_at', 'created_at']);

        return response()->json([
            'data' => $rows->take($limit)->map(fn ($n) => ['id' => $n->id, 'type' => $n->type, 'data' => $n->data, 'read' => $n->read_at !== null, 'at' => $n->type === 'tap' && isset($n->data['at']) ? $n->data['at'] : $n->created_at->toIso8601String()])->values(), // tap gabungan: waktu tap terakhir
            'meta' => ['unread' => $this->scoped()->whereNull('read_at')->count(), 'has_more' => $rows->count() > $limit],
        ]);
    }

    public function read(Request $request): JsonResponse
    {
        $ids = $request->validate(['ids' => ['nullable', 'array'], 'ids.*' => ['integer']])['ids'] ?? null;
        $this->scoped()->whereNull('read_at')->when($ids, fn ($q) => $q->whereIn('id', $ids))->update(['read_at' => now()]);

        return response()->json(['data' => ['ok' => true]]);
    }

    private function scoped()
    {
        $tid = $this->tenant()->id;

        return Notification::where('user_id', $this->user()->id)
            ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tid));
    }
}
