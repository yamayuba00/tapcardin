<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Email blast promo (panel, comms.manage). Isi markdown dirender BlastMarkdown; penerima di email_blast_recipients. */
class EmailBlast extends Model
{
    use HasPublicId;

    public const AUDIENCES = ['all', 'personal', 'company', 'plan', 'test'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(EmailBlastRecipient::class, 'blast_id');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'scheduled'], true);
    }

    /** Hitung ulang jumlah per status dari tabel penerima; selesai bila tak ada lagi yang pending. */
    public function refreshCounts(): void
    {
        $by = EmailBlastRecipient::where('blast_id', $this->id)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $this->fill([
            'total_count' => (int) $by->sum(), 'sent_count' => (int) ($by['sent'] ?? 0),
            'failed_count' => (int) ($by['failed'] ?? 0), 'skipped_count' => (int) ($by['skipped'] ?? 0),
        ]);
        if ($this->status === 'sending' && ! ($by['pending'] ?? 0)) {
            $this->fill(['status' => 'sent', 'finished_at' => now()]);
        }
        $this->save();
    }
}
