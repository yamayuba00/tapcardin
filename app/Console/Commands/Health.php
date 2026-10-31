<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/** TRD §14 — `tapcard:health`: queue lag, job gagal, ukuran tabel, DB, disk, dan ambang Redis (TRD §3.2). */
class Health extends Command
{
    protected $signature = 'tapcard:health {--json}';

    protected $description = 'Periksa kesehatan sistem dan ambang pemasangan Redis';

    public function handle(): int
    {
        $h = self::collect();
        if ($this->option('json')) {
            $this->line(json_encode($h, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }
        foreach ($h['checks'] as $c) {
            $this->line(sprintf('%-4s %-28s %s', $c['ok'] ? 'OK' : 'WARN', $c['label'], $c['value']));
        }

        return collect($h['checks'])->every('ok') ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{checks: list<array{label:string,value:string,ok:bool,hint?:string}>, redis_recommended: bool} */
    public static function collect(): array
    {
        $checks = [];
        try {
            DB::select('select 1');
            $checks[] = ['label' => 'Koneksi database', 'value' => 'terhubung', 'ok' => true];
        } catch (Throwable $e) {
            return ['checks' => [['label' => 'Koneksi database', 'value' => $e->getMessage(), 'ok' => false]], 'redis_recommended' => false];
        }

        $oldest = DB::table('jobs')->whereNull('reserved_at')->min('available_at');
        $lag = $oldest ? max(0, time() - (int) $oldest) : 0;
        $checks[] = ['label' => 'Umur job tertua', 'value' => $lag.' detik', 'ok' => $lag < 60, 'hint' => 'Tertunda > 1 menit rutin → pasang Redis'];
        $pending = DB::table('jobs')->count();
        $checks[] = ['label' => 'Job menunggu', 'value' => (string) $pending, 'ok' => $pending < 1000];
        $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
        $checks[] = ['label' => 'Job gagal 24 jam', 'value' => (string) $failed, 'ok' => $failed === 0];

        $taps = DB::table('taps')->where('created_at', '>=', now()->subDay())->count();
        $checks[] = ['label' => 'Tap 24 jam', 'value' => number_format($taps, 0, ',', '.'), 'ok' => $taps < 50000, 'hint' => '> ±50.000/hari → pasang Redis'];

        $online = DB::table('sessions')->where('last_activity', '>=', time() - 300)->whereNotNull('user_id')->count();
        $checks[] = ['label' => 'Pengguna online (5 menit)', 'value' => (string) $online, 'ok' => $online < 200, 'hint' => '> ±200 → pasang Redis untuk SSE'];

        foreach (['cache', 'sessions', 'jobs'] as $t) {
            $size = DB::selectOne('select pg_total_relation_size(?) as s', [$t])->s ?? 0;
            $checks[] = ['label' => "Ukuran tabel $t", 'value' => self::bytes((int) $size), 'ok' => $size < 512 * 1024 * 1024];
        }

        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());
        if ($free !== false && $total) {
            $pct = round($free / $total * 100);
            $checks[] = ['label' => 'Ruang disk tersisa', 'value' => self::bytes((int) $free)." ($pct%)", 'ok' => $pct > 10];
        }

        $redis = $lag >= 60 || $taps >= 50000 || $online >= 200;

        return ['checks' => $checks, 'redis_recommended' => $redis, 'drivers' => [
            'cache' => config('cache.default'), 'queue' => config('queue.default'), 'session' => config('session.driver'),
        ]];
    }

    private static function bytes(int $b): string
    {
        $u = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = $b > 0 ? (int) floor(log($b, 1024)) : 0;

        return round($b / 1024 ** $i, 1).' '.$u[$i];
    }
}
