<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Permintaan refund atas pesanan kartu/lanyard. Diajukan staf → diputuskan orang lain → dana dikembalikan HANYA ke
 * pembayar asal (snapshot `payer`) → dicatat dengan nomor referensi transfer.
 */
class Refund extends Model
{
    use HasPublicId;

    public const LABELS = [
        'requested' => 'Menunggu keputusan', 'approved' => 'Disetujui — menunggu transfer', 'paid' => 'Dibayar',
        'rejected' => 'Ditolak', 'cancelled' => 'Dibatalkan',
    ];

    /** Status yang menghitung sebagai refund "aktif" (satu per pesanan). */
    public const ACTIVE = ['requested', 'approved', 'paid'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer', 'evidence' => 'array', 'payer' => 'array',
            'requires_owner' => 'boolean', 'self_approved' => 'boolean',
            'decided_at' => 'datetime', 'paid_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withoutGlobalScopes();
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withoutGlobalScopes();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function payoutBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function label(): string
    {
        return self::LABELS[$this->status] ?? $this->status;
    }
}
