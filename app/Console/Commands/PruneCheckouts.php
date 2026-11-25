<?php

namespace App\Console\Commands;

use App\Models\Checkout;
use Illuminate\Console\Command;

/** Hapus draf checkout yang belum dibayar dan sudah kedaluwarsa (lebih dari sehari lewat batas). */
class PruneCheckouts extends Command
{
    protected $signature = 'tapcard:checkouts-prune';

    protected $description = 'Hapus draf checkout yang kedaluwarsa dan belum dibayar';

    public function handle(): int
    {
        $n = Checkout::withoutGlobalScopes()->where('status', 'open')->where('expires_at', '<', now()->subDay())->delete();
        $this->info("$n draf checkout dihapus.");

        return self::SUCCESS;
    }
}
