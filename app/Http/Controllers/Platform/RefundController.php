<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Finance\Refunds;
use App\Authz\Approvers;
use App\Authz\PlatformAccess;
use App\Models\Order;
use App\Models\Refund;
use App\Support\Http\Table;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Panel → Refund. Pengajuan (finance.refund) → keputusan oleh orang lain (finance.refund.approve; nominal besar:
 * finance.refund.large) → pencatatan transfer ke pembayar asal (finance.refund.payout). Tujuan dana tidak bisa diubah.
 */
class RefundController extends PlatformController
{
    public function index(Request $request): JsonResponse
    {
        $this->can('finance.view');
        $q = Refund::with(['order', 'tenant', 'requester', 'decider'])
            ->when($request->query('status'), fn ($w, $s) => $w->where('status', $s))
            ->when(trim((string) $request->query('q')), fn ($w, $s) => $w->whereHas('order', fn ($o) => $o->where('number', 'ilike', "%$s%")));

        return Table::json(Table::paginate($q, $request, ['created_at' => 'created_at', 'amount' => 'amount', 'status' => 'status'], '-created_at', [], 25),
            fn (Refund $r) => self::row($r), $request);
    }

    public function show(Refund $refund): JsonResponse
    {
        $this->can('finance.view');

        return $this->ok(self::row($refund->load(['order', 'tenant', 'requester', 'decider', 'payoutBy'])));
    }

    /** POST /api/orders/{order}/refunds */
    public function store(Request $request, string $order): JsonResponse
    {
        $this->can('finance.refund');
        $data = $request->validate([
            'reason_code' => ['required', 'string', 'in:'.implode(',', array_keys(Refunds::REASONS))],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'amount' => ['nullable', 'integer', 'min:1'],
            'evidence' => ['nullable', 'array', 'max:5'],
            'evidence.*' => ['string', 'max:300'],
        ], ['reason.required' => 'Jelaskan alasan refund.', 'reason.min' => 'Alasan terlalu singkat.']);
        $o = Order::acrossTenants()->where('public_id', $order)->firstOrFail();
        $refund = Refunds::request($o, auth()->user(), $data);

        return $this->ok(self::row($refund->load(['order', 'tenant', 'requester'])), 201);
    }

    public function approve(Request $request, Refund $refund): JsonResponse
    {
        $this->can('finance.refund.approve');
        $data = $request->validate(['note' => ['nullable', 'string', 'max:300']]);
        $r = Refunds::approve($refund, auth()->user(), $data['note'] ?? null);

        return $this->ok(self::row($r->load(['order', 'tenant', 'requester', 'decider'])));
    }

    public function reject(Request $request, Refund $refund): JsonResponse
    {
        $this->can('finance.refund.approve');
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:300']], ['note.required' => 'Alasan penolakan wajib diisi.']);
        $r = Refunds::reject($refund, auth()->user(), $data['note']);

        return $this->ok(self::row($r->load(['order', 'tenant', 'requester', 'decider'])));
    }

    /** POST /api/refunds/{id}/payout — dana sudah ditransfer ke pembayar asal (di luar sistem); catat bukti transfer. */
    public function payout(Request $request, Refund $refund): JsonResponse
    {
        $this->can('finance.refund.payout');
        $data = $request->validate([
            'method' => ['required', 'string', 'max:60'],
            'reference' => ['required', 'string', 'min:4', 'max:80'],
            'to_original_payer' => ['accepted'],
        ], ['to_original_payer.accepted' => 'Konfirmasi bahwa dana dikembalikan ke pembayar asal.', 'reference.required' => 'Nomor referensi transfer wajib diisi.']);
        $r = Refunds::payout($refund, auth()->user(), $data['method'], $data['reference']);

        return $this->ok(self::row($r->load(['order', 'tenant', 'requester', 'decider', 'payoutBy'])));
    }

    public function cancel(Refund $refund): JsonResponse
    {
        $this->can('finance.refund');
        $r = Refunds::cancel($refund, auth()->user());

        return $this->ok(self::row($r->load(['order', 'tenant', 'requester'])));
    }

    public static function row(Refund $r): array
    {
        $me = auth()->user();
        $access = app(PlatformAccess::class);
        $perm = Refunds::decidePermission($r);
        $other = fn (string $p) => $r->requested_by !== $me->id || Approvers::solo($p, $me->id);

        return [
            'id' => $r->public_id,
            'order' => $r->order ? ['id' => $r->order->public_id, 'number' => $r->order->number] : null,
            'tenant' => $r->tenant ? ['id' => $r->tenant->public_id, 'name' => $r->tenant->name] : null,
            'amount' => $r->amount, 'reason_code' => $r->reason_code, 'reason_label' => Refunds::REASONS[$r->reason_code] ?? $r->reason_code,
            'reason' => $r->reason, 'evidence' => $r->evidence, 'status' => $r->status, 'status_label' => $r->label(),
            'requires_owner' => $r->requires_owner, 'self_approved' => $r->self_approved,
            'requested_by' => $r->requester?->email, 'decided_by' => $r->decider?->email, 'decision_note' => $r->decision_note,
            'payer' => $r->payer, 'payout_method' => $r->payout_method, 'payout_reference' => $r->payout_reference,
            'paid_by' => $r->relationLoaded('payoutBy') ? $r->payoutBy?->email : null,
            'created_at' => self::dt($r->created_at), 'decided_at' => self::dt($r->decided_at), 'paid_at' => self::dt($r->paid_at),
            'can_decide' => $r->status === 'requested' && $access->has($perm) && $other($perm),
            'needs_owner_you_lack' => $r->status === 'requested' && ! $access->has($perm),
            'can_payout' => $r->status === 'approved' && $access->has('finance.refund.payout') && $other('finance.refund.payout'),
            'can_cancel' => ($r->status === 'requested' && ($r->requested_by === $me->id || $access->has('finance.refund.approve')))
                || ($r->status === 'approved' && $access->has('finance.refund.approve')),
        ];
    }
}
