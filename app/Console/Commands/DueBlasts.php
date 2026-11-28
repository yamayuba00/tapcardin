<?php

namespace App\Console\Commands;

use App\Jobs\SendEmailBlast;
use App\Models\EmailBlast;
use Illuminate\Console\Command;

/** Email blast terjadwal yang waktunya tiba → status `sending` (sekali, atomik) lalu SendEmailBlast di antrean `mail`. */
class DueBlasts extends Command
{
    protected $signature = 'tapcard:blasts';

    protected $description = 'Kirim email blast terjadwal yang sudah waktunya';

    public function handle(): int
    {
        $n = 0;
        EmailBlast::where('status', 'scheduled')->where('scheduled_at', '<=', now())->orderBy('id')->pluck('id')
            ->each(function (int $id) use (&$n) {
                if (EmailBlast::whereKey($id)->where('status', 'scheduled')->update(['status' => 'sending', 'started_at' => now()])) {
                    SendEmailBlast::dispatch($id);
                    $n++;
                }
            });
        $this->info("$n email blast mulai dikirim.");

        return self::SUCCESS;
    }
}
