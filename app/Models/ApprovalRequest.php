<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Permintaan persetujuan untuk aksi keuangan sensitif. Aksi baru dijalankan setelah DISETUJUI orang lain
 * (pengaju ≠ penyetuju); ditolak / kedaluwarsa = tidak ada yang berubah.
 */
class ApprovalRequest extends Model
{
    use HasPublicId;

    public const TYPES = [
        'invoice.mark_paid' => 'Tandai invoice lunas manual',
        'invoice.void' => 'Batalkan invoice',
        'plan.grant' => 'Beri paket tanpa pembayaran',
    ];

    public const LABELS = [
        'pending' => 'Menunggu persetujuan', 'approved' => 'Disetujui & dijalankan', 'rejected' => 'Ditolak',
        'cancelled' => 'Dibatalkan pengaju', 'expired' => 'Kedaluwarsa', 'failed' => 'Disetujui, gagal dijalankan',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array', 'result' => 'array', 'amount' => 'integer', 'self_approved' => 'boolean',
            'decided_at' => 'datetime', 'executed_at' => 'datetime', 'expires_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** Status yang ditampilkan: permintaan tertunda yang lewat masa berlaku dianggap kedaluwarsa. */
    public function effectiveStatus(): string
    {
        return $this->status === 'pending' && $this->expires_at->isPast() ? 'expired' : $this->status;
    }
}
