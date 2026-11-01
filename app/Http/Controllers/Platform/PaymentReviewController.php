<?php

namespace App\Http\Controllers\Platform;

use App\Exceptions\ApiError;
use App\Models\PaymentReview;
use App\Support\Audit;
use App\Support\Http\Table;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Panel → Pembayaran perlu tinjauan: uang/kabar masuk yang tidak bisa diproses otomatis (nominal beda, referensi tak dikenal, dst.). */
class PaymentReviewController extends PlatformController
{
    public function index(Request $request): JsonResponse
    {
        $this->can('finance.reviews');
        $q = PaymentReview::with(['invoice', 'tenant', 'resolver'])
            ->when($request->query('status'), fn ($w, $s) => $w->where('status', $s));

        return Table::json(Table::paginate($q, $request, ['created_at' => 'created_at', 'kind' => 'kind', 'status' => 'status'], '-created_at', ['message'], 25),
            fn (PaymentReview $p) => self::row($p), $request);
    }

    /** POST /api/payment-reviews/{id}/resolve {outcome: resolved|dismissed, note} */
    public function resolve(Request $request, PaymentReview $review): JsonResponse
    {
        $this->can('finance.reviews');
        $data = $request->validate([
            'outcome' => ['required', 'in:resolved,dismissed'],
            'note' => ['required', 'string', 'min:5', 'max:300'],
        ], ['note.required' => 'Catatan penyelesaian wajib diisi (mis. “direfund, ref. TRX-123” atau “salah kirim, abaikan”).']);
        $n = PaymentReview::whereKey($review->id)->where('status', 'open')->update([
            'status' => $data['outcome'], 'resolution_note' => $data['note'], 'resolved_by' => auth()->id(), 'resolved_at' => now(), 'updated_at' => now(),
        ]);
        if (! $n) {
            throw new ApiError('review_closed', 'Tinjauan ini sudah ditutup.', 409);
        }
        Audit::platform('payment.review_closed', $review->fresh(), null, ['outcome' => $data['outcome'], 'note' => $data['note']]);

        return $this->ok(self::row($review->fresh(['invoice', 'tenant', 'resolver'])));
    }

    public static function row(PaymentReview $p): array
    {
        return [
            'id' => $p->public_id, 'kind' => $p->kind, 'kind_label' => PaymentReview::KINDS[$p->kind] ?? $p->kind, 'message' => $p->message,
            'expected_amount' => $p->expected_amount, 'received_amount' => $p->received_amount,
            'invoice' => $p->invoice ? ['id' => $p->invoice->public_id, 'number' => $p->invoice->number, 'status' => $p->invoice->status] : null,
            'tenant' => $p->tenant ? ['id' => $p->tenant->public_id, 'name' => $p->tenant->name] : null,
            'status' => $p->status, 'resolution_note' => $p->resolution_note, 'resolved_by' => $p->resolver?->email,
            'created_at' => self::dt($p->created_at), 'resolved_at' => self::dt($p->resolved_at),
        ];
    }
}
