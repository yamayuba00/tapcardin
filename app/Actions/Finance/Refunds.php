<?php

namespace App\Actions\Finance;

use App\Authz\PlatformAccess;
use App\Exceptions\ApiError;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use App\Support\Audit;
use App\Support\Notify;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Refund pesanan kartu / lanyard.
 *  - Tujuan dana TIDAK bisa dipilih: selalu pembayar asal (snapshot `payer` dari invoice yang lunas). Pencatatan transfer
 *    mewajibkan konfirmasi "dikembalikan ke pembayar asal".
 *  - Pengaju ≠ pemutus ≠ (tanpa mode tunggal) pencatat transfer. Nominal besar / alasan "lainnya" / pesanan dalam produksi → Pemilik.
 *  - Satu pesanan hanya bisa di-refund sekali (unique index parsial di DB + cek di sini); nominal ≤ yang dibayar.
 */
final class Refunds
{
    public const REASONS = [
        'not_started' => 'Dibatalkan sebelum produksi',
        'paid_after_cancel' => 'Dibayar setelah pesanan dibatalkan',
        'duplicate_payment' => 'Pembayaran ganda',
        'defect' => 'Barang cacat',
        'wrong_print' => 'Salah cetak (kesalahan kami)',
        'not_delivered' => 'Barang tidak sampai',
        'other' => 'Lainnya (perlu Pemilik)',
    ];

    /** Alasan yang berlaku per status pesanan — sesuai kebijakan: produksi berjalan = tidak ada refund kecuali kesalahan kami. */
    private const ALLOWED = [
        'cancelled' => ['not_started', 'paid_after_cancel', 'duplicate_payment', 'other'],
        'paid' => ['not_started', 'duplicate_payment', 'other'],
        'in_production' => ['duplicate_payment', 'other'],
        'shipped' => ['defect', 'wrong_print', 'not_delivered', 'duplicate_payment', 'other'],
        'delivered' => ['defect', 'wrong_print', 'duplicate_payment', 'other'],
    ];

    private const NEEDS_EVIDENCE = ['defect', 'wrong_print', 'not_delivered'];

    /** @return array<string,string> kode => label yang boleh dipilih untuk pesanan ini saat ini. */
    public static function allowedReasons(Order $o): array
    {
        $out = [];
        foreach (self::ALLOWED[$o->status] ?? [] as $code) {
            if (self::windowError($o, $code) === null) {
                $out[$code] = self::REASONS[$code];
            }
        }

        return $out;
    }

    public static function paidInvoice(Order $o): ?Invoice
    {
        return Invoice::withoutGlobalScopes()->where('order_id', $o->id)->where('status', 'paid')->latest('id')->first();
    }

    private static function windowError(Order $o, string $code): ?string
    {
        $cfg = config('tapcard.refund');
        if ($o->status === 'delivered' && in_array($code, ['defect', 'wrong_print'], true) && $o->delivered_at?->lt(now()->subDays($cfg['claim_window_days']))) {
            return "Batas klaim {$cfg['claim_window_days']} hari setelah barang tiba sudah lewat.";
        }
        if ($o->status === 'shipped' && $code === 'not_delivered' && (! $o->shipped_at || $o->shipped_at->gt(now()->subDays($cfg['not_delivered_after_days'])))) {
            return "Klaim “tidak sampai” baru bisa diajukan {$cfg['not_delivered_after_days']} hari setelah dikirim.";
        }

        return null;
    }

    /** @param  array{reason_code:string, reason:string, amount?:?int, evidence?:array}  $in */
    public static function request(Order $order, User $by, array $in): Refund
    {
        $code = (string) $in['reason_code'];
        try {
            $refund = DB::transaction(function () use ($order, $by, $in, $code) {
                $o = Order::withoutGlobalScopes()->whereKey($order->id)->lockForUpdate()->firstOrFail();
                $invoice = self::paidInvoice($o)
                    ?? throw new ApiError('order_not_paid', 'Pesanan ini belum punya pembayaran lunas, jadi tidak ada yang bisa di-refund.');
                if (Refund::where('order_id', $o->id)->whereIn('status', Refund::ACTIVE)->exists()) {
                    throw new ApiError('refund_exists', 'Pesanan ini sudah punya refund yang berjalan atau sudah pernah di-refund. Satu pesanan hanya bisa di-refund sekali.', 409);
                }
                if (! in_array($code, self::ALLOWED[$o->status] ?? [], true)) {
                    throw new ApiError('refund_not_allowed', 'Alasan ini tidak berlaku untuk pesanan berstatus “'.$o->label().'”.', 422, ['field' => 'reason_code']);
                }
                if ($msg = self::windowError($o, $code)) {
                    throw new ApiError('refund_window', $msg, 422, ['field' => 'reason_code']);
                }

                $total = (int) $invoice->total;
                $room = $total - (int) $invoice->refunded_amount;
                $amount = ($in['amount'] ?? null) !== null ? (int) $in['amount'] : $room;
                if ($amount < 1 || $amount > $room) {
                    throw new ApiError('refund_amount_invalid', 'Nominal refund tidak boleh melebihi yang dibayar (Rp '.number_format($room, 0, ',', '.').').', 422, ['field' => 'amount']);
                }
                $evidence = array_values(array_filter(array_map(fn ($e) => mb_substr(trim((string) $e), 0, 300), (array) ($in['evidence'] ?? []))));
                if (in_array($code, self::NEEDS_EVIDENCE, true) && ! $evidence) {
                    throw new ApiError('evidence_required', 'Lampirkan bukti (tautan foto / video, atau keterangan) untuk klaim ini.', 422, ['field' => 'evidence']);
                }
                $reason = trim((string) $in['reason']);
                if ($code === 'other' && mb_strlen($reason) < 20) {
                    throw new ApiError('reason_too_short', 'Untuk alasan “Lainnya”, jelaskan minimal 20 karakter.', 422, ['field' => 'reason']);
                }

                return Refund::create([
                    'status' => 'requested', 'order_id' => $o->id, 'invoice_id' => $invoice->id, 'tenant_id' => $o->tenant_id, 'amount' => $amount,
                    'reason_code' => $code, 'reason' => mb_substr($reason, 0, 500), 'evidence' => $evidence,
                    'requires_owner' => $amount >= (int) config('tapcard.refund.owner_threshold') || $code === 'other' || $o->status === 'in_production',
                    'requested_by' => $by->id, 'payer' => self::payer($invoice),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw new ApiError('refund_exists', 'Pesanan ini sudah punya refund yang berjalan atau sudah pernah di-refund.', 409);
        }
        $refund = $refund->fresh();
        Audit::platform('refund.requested', $refund, null, ['amount' => $refund->amount, 'reason' => $code, 'requires_owner' => $refund->requires_owner]);

        return $refund;
    }

    public static function approve(Refund $refund, User $by, ?string $note): Refund
    {
        $solo = false;
        DB::transaction(function () use ($refund, $by, $note, &$solo) {
            $r = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if ($r->status !== 'requested') {
                throw new ApiError('refund_decided', 'Refund ini sudah diputuskan.', 409);
            }
            $perm = self::decidePermission($r);
            if (! app(PlatformAccess::class)->has($perm)) {
                throw new ApiError('owner_required', 'Refund ini perlu persetujuan Pemilik Platform (nominal besar / alasan khusus / pesanan dalam produksi).', 403);
            }
            $solo = Approvals::guardSelf($r->requested_by, $by, $perm);
            $r->update(['status' => 'approved', 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $note ? mb_substr($note, 0, 300) : null, 'self_approved' => $solo]);

            // refund penuh atas pesanan yang belum diproduksi → batalkan pesanan agar tidak masuk produksi
            $order = Order::withoutGlobalScopes()->whereKey($r->order_id)->lockForUpdate()->first();
            if ($order && $order->status === 'paid' && $r->amount >= (int) $order->total) {
                $order->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => 'Refund disetujui']);
            }
        });
        $fresh = $refund->fresh();
        Audit::platform('refund.approved', $fresh, null, ['amount' => $fresh->amount, 'self_approved' => $solo]);

        return $fresh;
    }

    public static function reject(Refund $refund, User $by, string $note): Refund
    {
        $solo = false;
        DB::transaction(function () use ($refund, $by, $note, &$solo) {
            $r = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if ($r->status !== 'requested') {
                throw new ApiError('refund_decided', 'Refund ini sudah diputuskan.', 409);
            }
            $solo = Approvals::guardSelf($r->requested_by, $by, 'finance.refund.approve');
            $r->update(['status' => 'rejected', 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => mb_substr($note, 0, 300), 'self_approved' => $solo]);
        });
        $fresh = $refund->fresh();
        Audit::platform('refund.rejected', $fresh, null, ['note' => $note]);

        return $fresh;
    }

    /** Catat bahwa dana sudah dikembalikan ke pembayar asal (transfer dilakukan di luar sistem). */
    public static function payout(Refund $refund, User $by, string $method, string $reference): Refund
    {
        try {
            DB::transaction(function () use ($refund, $by, $method, $reference) {
                $r = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
                if ($r->status !== 'approved') {
                    throw new ApiError('refund_not_approved', 'Hanya refund yang sudah disetujui yang bisa dicatat transfernya.', 409);
                }
                $solo = Approvals::guardSelf($r->requested_by, $by, 'finance.refund.payout');
                $r->update([
                    'status' => 'paid', 'payout_method' => mb_substr($method, 0, 60), 'payout_reference' => mb_substr($reference, 0, 80),
                    'paid_by' => $by->id, 'paid_at' => now(), 'self_approved' => $r->self_approved || $solo,
                ]);
                Invoice::withoutGlobalScopes()->whereKey($r->invoice_id)->lockForUpdate()->increment('refunded_amount', $r->amount);
            });
        } catch (UniqueConstraintViolationException) {
            throw new ApiError('payout_reference_used', 'Referensi transfer ini sudah dipakai untuk refund lain.', 409);
        }
        $fresh = $refund->fresh();
        Audit::platform('refund.paid', $fresh, null, ['amount' => $fresh->amount, 'method' => $method, 'reference' => $reference]);

        $order = Order::withoutGlobalScopes()->find($fresh->order_id);
        if ($order?->user_id) {
            Notify::user($order->user_id, $order->tenant_id, 'order_status', [
                'id' => $order->public_id, 'number' => $order->number, 'status' => 'refunded',
                'label' => 'Refund Rp '.number_format($fresh->amount, 0, ',', '.').' dikembalikan ke metode pembayaran asal',
            ]);
        }

        return $fresh;
    }

    public static function cancel(Refund $refund, User $by): Refund
    {
        $access = app(PlatformAccess::class);
        $isRequester = $refund->requested_by === $by->id;
        $canDecide = $access->has('finance.refund.approve');
        $ok = ($refund->status === 'requested' && ($isRequester || $canDecide)) || ($refund->status === 'approved' && $canDecide);
        if (! $ok) {
            throw new ApiError('refund_not_cancellable', 'Refund ini tidak bisa dibatalkan oleh Anda pada status saat ini.', 403);
        }
        $n = Refund::whereKey($refund->id)->whereIn('status', ['requested', 'approved'])->update(['status' => 'cancelled', 'updated_at' => now()]);
        if (! $n) {
            throw new ApiError('refund_decided', 'Refund ini sudah diputuskan.', 409);
        }
        $fresh = $refund->fresh();
        Audit::platform('refund.cancelled', $fresh, null, ['by' => $by->id]);

        return $fresh;
    }

    /** Izin yang dibutuhkan untuk memutuskan: nominal besar / khusus → izin Pemilik. */
    public static function decidePermission(Refund $r): string
    {
        return $r->requires_owner ? 'finance.refund.large' : 'finance.refund.approve';
    }

    /** Snapshot pembayar asal — satu-satunya tujuan pengembalian dana. @return array<string,mixed> */
    private static function payer(Invoice $i): array
    {
        $snap = (array) $i->billing_snapshot;

        return array_filter([
            'invoice' => $i->number, 'provider' => $i->payment_provider, 'method' => $i->payment_method, 'reference' => $i->payment_ref,
            'paid_at' => $i->paid_at?->toIso8601String(), 'name' => $snap['name'] ?? null, 'email' => $snap['email'] ?? null, 'amount_paid' => (int) $i->total,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
