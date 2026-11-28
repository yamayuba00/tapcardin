<?php

namespace App\Jobs;

use App\Models\EmailBlast;
use App\Models\EmailBlastRecipient;
use App\Support\Blast\BlastAudience;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Antrean `mail`: susun daftar penerima blast (insert per 500, unik per alamat → aman dijalankan ulang), lalu bagi
 * penerima `pending` ke job SendBlastBatch berisi ≤50 email dengan jeda agar laju rata-rata ≤ tapcard.blast.per_minute.
 */
class SendEmailBlast implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const CHUNK = 500;

    public const BATCH = 50;

    public int $timeout = 600;

    public int $tries = 3;

    public function __construct(public int $blastId)
    {
        $this->onQueue('mail');
    }

    public function handle(): void
    {
        $blast = EmailBlast::find($this->blastId);
        if (! $blast || $blast->status !== 'sending') {
            return;
        }

        BlastAudience::query($blast->audience, $blast->plan_id)->chunkById(self::CHUNK, function ($rows) use ($blast) {
            EmailBlastRecipient::insertOrIgnore($rows->map(fn ($r) => [
                'blast_id' => $blast->id, 'user_id' => $r->user_id, 'email' => $r->email, 'status' => 'pending',
            ])->unique('email')->values()->all());
        });

        $perMinute = max(1, (int) config('tapcard.blast.per_minute', 60));
        $size = min(self::BATCH, $perMinute);
        $gap = (int) ceil($size * 60 / $perMinute); // detik antar batch
        $i = 0;
        EmailBlastRecipient::where('blast_id', $blast->id)->where('status', 'pending')->select('id')
            ->chunkById($size, function ($rows) use ($blast, $gap, &$i) {
                SendBlastBatch::dispatch($blast->id, $rows->pluck('id')->all())->delay(now()->addSeconds($i++ * $gap));
            });

        $blast->refresh()->refreshCounts();
    }
}
