<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Finance\Approvals;
use App\Authz\Approvers;
use App\Authz\PlatformAccess;
use App\Models\ApprovalRequest;
use App\Support\Http\Table;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Panel → Persetujuan: antrean permintaan aksi keuangan sensitif (lunas manual, batal invoice, beri paket). */
class ApprovalController extends PlatformController
{
    public function index(Request $request): JsonResponse
    {
        $this->can('finance.view');
        $q = ApprovalRequest::with(['requester', 'decider'])
            ->when($request->query('status') === 'pending', fn ($w) => $w->where('status', 'pending')->where('expires_at', '>', now()))
            ->when($request->query('status') && $request->query('status') !== 'pending', fn ($w) => $w->where('status', $request->query('status')))
            ->when($request->query('type'), fn ($w, $t) => $w->where('type', $t));

        return Table::json(Table::paginate($q, $request, ['created_at' => 'created_at', 'status' => 'status', 'amount' => 'amount'], '-created_at', ['reference', 'summary'], 25),
            fn (ApprovalRequest $a) => self::row($a), $request);
    }

    public function approve(Request $request, ApprovalRequest $approval): JsonResponse
    {
        $this->can('finance.approve');
        $data = $request->validate(['note' => ['nullable', 'string', 'max:300']]);
        $a = Approvals::approve($approval, auth()->user(), $data['note'] ?? null);

        return $this->ok(self::row($a->load(['requester', 'decider'])));
    }

    public function reject(Request $request, ApprovalRequest $approval): JsonResponse
    {
        $this->can('finance.approve');
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:300']], ['note.required' => 'Alasan penolakan wajib diisi.']);
        $a = Approvals::reject($approval, auth()->user(), $data['note']);

        return $this->ok(self::row($a->load(['requester', 'decider'])));
    }

    public function cancel(ApprovalRequest $approval): JsonResponse
    {
        $this->can('finance.invoices');
        $a = Approvals::cancel($approval, auth()->user());

        return $this->ok(self::row($a->load(['requester', 'decider'])));
    }

    public static function row(ApprovalRequest $a): array
    {
        $me = auth()->user();
        $status = $a->effectiveStatus();

        return [
            'id' => $a->public_id, 'type' => $a->type, 'type_label' => ApprovalRequest::TYPES[$a->type] ?? $a->type,
            'summary' => $a->summary, 'reference' => $a->reference, 'reason' => $a->reason, 'amount' => $a->amount,
            'status' => $status, 'status_label' => ApprovalRequest::LABELS[$status] ?? $status,
            'requested_by' => $a->requester?->email, 'decided_by' => $a->decider?->email, 'decision_note' => $a->decision_note,
            'self_approved' => $a->self_approved, 'result' => $a->result,
            'created_at' => self::dt($a->created_at), 'expires_at' => self::dt($a->expires_at), 'decided_at' => self::dt($a->decided_at),
            'mine' => $a->requested_by === $me->id,
            'can_decide' => $status === 'pending' && app(PlatformAccess::class)->has('finance.approve')
                && ($a->requested_by !== $me->id || Approvers::solo('finance.approve', $me->id)),
            'can_cancel' => $status === 'pending' && $a->requested_by === $me->id,
        ];
    }
}
