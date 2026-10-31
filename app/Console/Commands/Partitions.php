<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** BSD §4.8 — buat partisi `taps` 2 bulan ke depan; drop partisi > 24 bulan setelah diagregasi. */
class Partitions extends Command
{
    protected $signature = 'tapcard:partitions';

    protected $description = 'Kelola partisi bulanan tabel taps';

    public function handle(): int
    {
        $start = Carbon::now('UTC')->startOfMonth();
        for ($i = 0; $i <= 2; $i++) {
            $from = $start->copy()->addMonths($i);
            $name = 'taps_'.$from->format('Y_m');
            $exists = DB::selectOne('select to_regclass(?) as r', [$name])->r;
            if (! $exists) {
                DB::statement(sprintf("CREATE TABLE %s PARTITION OF taps FOR VALUES FROM ('%s') TO ('%s')",
                    $name, $from->toDateString(), $from->copy()->addMonth()->toDateString()));
                $this->info("Partisi $name dibuat.");
            }
        }

        $cutoff = $start->copy()->subMonths(24);
        $old = DB::select("select c.relname as name from pg_inherits i join pg_class c on c.oid = i.inhrelid
            join pg_class p on p.oid = i.inhparent where p.relname = 'taps' and c.relname ~ '^taps_[0-9]{4}_[0-9]{2}$'");
        foreach ($old as $row) {
            $month = Carbon::createFromFormat('Y_m', substr($row->name, 5), 'UTC')->startOfMonth();
            if ($month->lt($cutoff)) {
                DB::statement("DROP TABLE {$row->name}");
                $this->warn("Partisi {$row->name} dihapus (retensi 24 bulan).");
            }
        }

        return self::SUCCESS;
    }
}
