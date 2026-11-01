<?php

namespace App\Actions\Finance;

use App\Actions\Billing\ApplyPayment;
use App\Actions\Billing\GrantPlan;
use App\Authz\Approvers;
use App\Exceptions\ApiError;
use App\Models\ApprovalRequest;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Payment\PaymentNotification;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Alur persetujuan aksi keuangan sensitif (pengganti "2FA" untuk staf keuangan): aksi TIDAK berjalan saat diajukan.
 * Orang lain (finance.approve) menyetujui atau menolak; baru setelah disetujui aksi dijalankan. Pengaju tidak boleh
 * menyetujui permintaannya sendiri, kecuali tidak ada staf lain yang berwenang (mode tunggal) — dicatat `self_approved`.
 */
final class Approvals
{
    /** @param  array<string,mixed>  $payload */
    public static function submit(string $type, array $payload, string $summary, string $reference, ?string $reason, ?int $amount, User $by): ApprovalRequest
    {
        $subject = $payload['invoice'] ?? $payload['tenant'] ?? null;
        if ($subject && ApprovalRequest::where('type', $type)->where('status', 'pending')->where('expires_at', '>', now())
            ->where(fn ($q) => $q->where('payload->invoice', $subject)->orWhere('payload->tenant', $subject))->exists()) {
            throw new ApiError('approval_pending', 'Sudah ada permintaan serupa yang menunggu persetujuan.', 409);
        }
        // satu bukti transfer hanya boleh dipakai untuk satu permintaan "tandai lunas"
        if ($type === 'invoice.mark_paid' && ApprovalRequest::where('type', $type)->whereRaw('lower(reference) = ?', [mb_strtolower($reference)])
            ->where(fn ($q) => $q->where('status', 'approved')->orWhere(fn ($w) => $w->where('status', 'pending')->where('expires_at', '>', now())))->exists()) {
            throw new ApiError('reference_used', 'Referensi transfer ini sudah dipakai di permintaan lain.', 409);
        }
        $request = ApprovalRequest::create([
            'status' => 'pending', 'type' => $type, 'summary' => mb_substr($summary, 0, 240), 'payload' => $payload, 'amount' => $amount,
            'reference' => mb_substr($reference, 0, 80), 'reason' => $reason ? mb_substr($reason, 0, 300) : null,
            'requested_by' => $by->id, 'expires_at' => now()->addDays((int) config('tapcard.refund.approval_ttl_days', 3)),
        ]);
        Audit::platform('approval.requested', $request, null, ['type' => $type, 'reference' => $request->reference, 'amount' => $amount]);

        return $request;
    }

    public static function approve(ApprovalRequest $request, User $by, ?string $note): ApprovalRequest
    {
        $solo = false;
        DB::transaction(function () use ($request, $by, $note, &$solo) {
            $r = ApprovalRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($r->effectiveStatus() === 'expired') {
                $r->update(['status' => 'expired']);
                throw new ApiError('approval_expired', 'Permintaan ini sudah kedaluwarsa. Minta pengaju mengajukan ulang.', 409);
            }
            if ($r->status !== 'pending') {
                throw new ApiError('approval_decided', 'Permintaan ini sudah diputuskan.', 409);
            }
            $solo = self::guardSelf($r->requested_by, $by, 'finance.approve');
            $r->update(['status' => 'approved', 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $note ? mb_substr($note, 0, 300) : null, 'self_approved' => $solo]);
        });
        $request = $request->fresh();
        Audit::platform('approval.approved', $request, null, ['type' => $request->type, 'reference' => $request->reference, 'self_approved' => $solo]);

        try {
            $result = self::execute($request);
            $request->update(['executed_at' => now(), 'result' => $result]);
        } catch (\Throwable $e) {
            $request->update(['status' => 'failed', 'result' => ['error' => mb_substr($e->getMessage(), 0, 240)]]);
            Audit::platform('approval.failed', $request, null, ['error' => mb_substr($e->getMessage(), 0, 240)]);
            throw $e;
        }

        return $request->fresh();
    }

    public static function reject(ApprovalRequest $request, User $by, string $note): ApprovalRequest
    {
        DB::transaction(function () use ($request, $by, $note) {
            $r = ApprovalRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($r->status !== 'pending') {
                throw new ApiError('approval_decided', 'Permintaan ini sudah diputuskan.', 409);
            }
            $solo = self::guardSelf($r->requested_by, $by, 'finance.approve');
            $r->update(['status' => 'rejected', 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => mb_substr($note, 0, 300), 'self_approved' => $solo]);
        });
        Audit::platform('approval.rejected', $request->fresh(), null, ['type' => $request->type, 'note' => $note]);

        return $request->fresh();
    }

    public static function cancel(ApprovalRequest $request, User $by): ApprovalRequest
    {
        if ($request->requested_by !== $by->id) {
            throw new ApiError('not_requester', 'Hanya pengaju yang bisa membatalkan permintaannya.', 403);
        }
        $n = ApprovalRequest::whereKey($request->id)->where('status', 'pending')->update(['status' => 'cancelled', 'updated_at' => now()]);
        if (! $n) {
            throw new ApiError('approval_decided', 'Permintaan ini sudah diputuskan.', 409);
        }
        Audit::platform('approval.cancelled', $request->fresh(), null, ['type' => $request->type]);

        return $request->fresh();
    }

    /** Pengaju ≠ pemutus. Mengembalikan true bila lolos karena mode tunggal (tidak ada staf lain yang berwenang). */
    public static function guardSelf(int $requesterId, User $by, string $permission): bool
    {
        if ($requesterId !== $by->id) {
            return false;
        }
        if (Approvers::solo($permission, $by->id)) {
            return true;
        }
        throw new ApiError('self_approval_forbidden', 'Anda tidak bisa memutuskan permintaan yang Anda ajukan sendiri. Minta staf lain yang berwenang.', 403);
    }

    /** @return array<string,mixed> */
    private static function execute(ApprovalRequest $r): array
    {
        $p = $r->payload;
        $meta = ['approval' => $r->public_id, 'requested_by' => $r->requested_by, 'approved_by' => $r->decided_by, 'reference' => $r->reference];

        return match ($r->type) {
            'invoice.mark_paid' => (function () use ($p, $meta) {
                $i = Invoice::acrossTenants()->where('public_id', $p['invoice'])->firstOrFail();
                if (! $i->isPayable()) {
                    throw new ApiError('invoice_not_payable', 'Invoice ini sudah tidak terbuka (sudah dibayar / dibatalkan).');
                }
                $outcome = app(ApplyPayment::class)->handle(new PaymentNotification('manual', 'manual-'.Str::ulid(), $i->number, PaymentNotification::PAID, (int) $i->total,
                    mb_substr((string) $p['method'], 0, 40), true, $meta));
                Audit::platform('invoice.marked_paid', $i, null, ['method' => $p['method'], 'reference' => $meta['reference'], 'outcome' => $outcome]);

                return ['outcome' => $outcome];
            })(),
            'invoice.void' => (function () use ($p, $r) {
                $i = Invoice::acrossTenants()->where('public_id', $p['invoice'])->firstOrFail();
                if (! $i->isPayable()) {
                    throw new ApiError('invoice_not_payable', 'Hanya invoice terbuka yang bisa dibatalkan.');
                }
                $i->update(['status' => 'void']);
                Audit::platform('invoice.voided', $i, null, ['reason' => $r->reason, 'reference' => $r->reference]);

                return ['status' => 'void'];
            })(),
            'plan.grant' => (function () use ($p, $r) {
                $tenant = Tenant::where('public_id', $p['tenant'])->firstOrFail();
                $plan = Plan::where('key', $p['plan'])->firstOrFail();
                app(GrantPlan::class)->handle($tenant, $plan, (int) $p['months'], (string) $r->reason);
                Audit::platform('subscription.granted', $tenant, null, ['plan' => $plan->key, 'months' => (int) $p['months'], 'reason' => $r->reason, 'reference' => $r->reference]);

                return ['plan' => $plan->key, 'months' => (int) $p['months']];
            })(),
            default => throw new ApiError('approval_unknown', 'Jenis permintaan tidak dikenal.'),
        };
    }
}
