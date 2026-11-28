<?php

namespace App\Jobs;

use App\Mail\BlastMail;
use App\Models\EmailBlast;
use App\Models\EmailBlastRecipient;
use App\Models\EmailUnsubscribe;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Antrean `mail`: kirim ≤50 email blast. Tiap penerima dikunci (FOR UPDATE) & hanya yang `pending` dikirim, jadi job
 * ganda/ulang tidak mengirim dua kali. Blast dibatalkan → berhenti; berhenti berlangganan/diblokir sesudah antre → skipped.
 */
class SendBlastBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 300;

    public int $tries = 3;

    /** @param  list<int>  $recipientIds */
    public function __construct(public int $blastId, public array $recipientIds)
    {
        $this->onQueue('mail');
    }

    public function handle(): void
    {
        $blast = EmailBlast::find($this->blastId);
        if (! $blast || $blast->status !== 'sending') {
            return;
        }

        foreach ($this->recipientIds as $id) {
            if (EmailBlast::whereKey($blast->id)->value('status') !== 'sending') {
                break; // dibatalkan di tengah jalan
            }
            DB::transaction(function () use ($blast, $id) {
                $r = EmailBlastRecipient::whereKey($id)->lockForUpdate()->first();
                if (! $r || $r->status !== 'pending') {
                    return;
                }
                if (EmailUnsubscribe::has($r->email)) {
                    $r->update(['status' => 'skipped', 'error' => 'Berhenti berlangganan']);

                    return;
                }
                if ($r->user_id && ! User::whereKey($r->user_id)->whereNull('blocked_at')->exists()) {
                    $r->update(['status' => 'skipped', 'error' => 'Pengguna diblokir/dihapus']);

                    return;
                }
                try {
                    Mail::to($r->email)->send(new BlastMail($blast->subject, $blast->preheader, $blast->body, $r->email, $blast->public_id));
                    $r->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);
                } catch (Throwable $e) {
                    report($e);
                    $r->update(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 250)]);
                }
            });
        }

        $blast->refresh()->refreshCounts();
    }
}
