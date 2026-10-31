<?php

namespace App\Http\Controllers\Workspace;

use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Fallback polling untuk SSE (TRD §9) + tandai dibaca. */
class NotificationController extends WorkspaceController
{
    public function index(Request $request): JsonResponse
    {
        $rows = $this->scoped()
            ->when($request->query('since'), fn ($q, $since) => $q->where('id', '>', (int) $since))
            ->orderByDesc('id')->limit(30)->get(['id', 'type', 'data', 'read_at', 'created_at']);

        return response()->json([
            'data' => $rows->map(fn ($n) => ['id' => $n->id, 'type' => $n->type, 'data' => $n->data, 'read' => $n->read_at !== null, 'at' => $n->created_at->toIso8601String()]),
            'meta' => ['unread' => $this->scoped()->whereNull('read_at')->count()],
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
