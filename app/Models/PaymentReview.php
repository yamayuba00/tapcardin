<?php

namespace App\Models;

use App\Support\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Antrean "pembayaran perlu tinjauan": uang/kabar pembayaran masuk tetapi tidak bisa diproses otomatis
 * (nominal beda, referensi tak dikenal, dibayar setelah pesanan / invoice dibatalkan). Sebelumnya ditolak diam-diam.
 * Muncul di panel → Persetujuan dan di Dashboard ("perlu tindakan"); diselesaikan staf dengan catatan.
 */
class PaymentReview extends Model
{
    use HasPublicId;

    public const KINDS = [
        'amount_mismatch' => 'Nominal tidak cocok',
        'unknown_invoice' => 'Referensi pembayaran tidak dikenal',
        'paid_after_cancel' => 'Dibayar setelah pesanan / invoice dibatalkan',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expected_amount' => 'integer', 'received_amount' => 'integer', 'resolved_at' => 'datetime'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(PaymentEvent::class, 'payment_event_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withoutGlobalScopes();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** Catat satu tinjauan per event pembayaran (idempoten) dan beri tahu staf keuangan. */
    public static function open(PaymentEvent $event, ?Invoice $invoice, string $kind, ?int $expected, ?int $received, string $message): self
    {
        $review = static::firstOrCreate(['payment_event_id' => $event->id], [
            'status' => 'open', 'invoice_id' => $invoice?->id, 'tenant_id' => $invoice?->tenant_id, 'kind' => $kind,
            'expected_amount' => $expected, 'received_amount' => $received, 'message' => mb_substr($message, 0, 240),
        ]);
        if ($review->wasRecentlyCreated) {
            Audit::platform('payment.review_opened', $review, null, ['kind' => $kind, 'invoice' => $invoice?->number, 'expected' => $expected, 'received' => $received]);
        }

        return $review;
    }
}
