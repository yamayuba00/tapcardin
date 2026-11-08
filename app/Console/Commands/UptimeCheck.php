<?php

namespace App\Console\Commands;

use App\Models\Monitor;
use App\Support\Uptime\Checker;
use Illuminate\Console\Command;

/** Cek semua monitor aktif yang sudah waktunya (scheduler tiap 10 detik; interval tiap monitor ≥ 30 detik). */
class UptimeCheck extends Command
{
    protected $signature = 'tapcard:uptime {--all : cek semua monitor aktif sekarang juga}';

    protected $description = 'Jalankan pemeriksaan uptime yang sudah jatuh tempo';

    public function handle(Checker $checker): int
    {
        $due = Monitor::where('is_active', true)
            ->when(! $this->option('all'), fn ($q) => $q->where(fn ($w) => $w->whereNull('next_check_at')->orWhere('next_check_at', '<=', now())))
            ->orderBy('id')->get();
        if ($due->isNotEmpty()) {
            $checker->run($due);
        }
        $this->line($due->count().' monitor dicek.');

        return self::SUCCESS;
    }
}
