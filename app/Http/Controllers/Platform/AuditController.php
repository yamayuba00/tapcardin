<?php

namespace App\Http\Controllers\Platform;

use App\Models\AuditLog;
use App\Support\Http\Table;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** PL-17 — semua aksi staf & perubahan sensitif, filter per aksi/tanggal. */
class AuditController extends PlatformController
{
    public function index(Request $request): JsonResponse
    {
        $this->can('audit.view');
        $logs = AuditLog::with('actor')
            ->when($request->query('scope'), fn ($q, $s) => $q->where('scope', $s))
            ->when($request->query('action'), fn ($q, $a) => $q->where('action', 'like', "$a%"))
            ->when($request->query('from'), fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($request->query('to'), fn ($q, $d) => $q->where('created_at', '<', Carbon::parse($d)->addDay()));
        $logs = Table::paginate($logs, $request, ['at' => 'created_at', 'action' => 'action', 'scope' => 'scope'], '-at', [], 50);

        return Table::json($logs, fn (AuditLog $l) => [
            'id' => $l->id,
            'at' => self::dt($l->created_at),
            'actor' => $l->actor?->email,
            'impersonated' => $l->impersonation_id !== null,
            'action' => $l->action,
            'scope' => $l->scope,
            'tenant_id' => $l->tenant_id,
            'subject' => "{$l->subject_type}#{$l->subject_id}",
            'before' => $l->before,
            'after' => $l->after,
        ]);
    }
}
