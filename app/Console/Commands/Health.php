<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Throwable;

/** TRD §14 — `tapcard:health`: queue lag, job gagal, ukuran tabel, DB, disk, dan ambang Redis (TRD §3.2). */
class Health extends Command
{
    /** Antrean yang dikerjakan worker (deploy/supervisor/tapcard.conf). */
    private const QUEUES = ['taps', 'notify', 'leads', 'default', 'render'];

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

        // antrean dibaca lewat API Queue → benar untuk driver database maupun redis (deploy/enable-redis.sh)
        $onRedis = config('queue.default') === 'redis' || config('cache.default') === 'redis';
        $hint = fn (string $h) => config('queue.default') === 'redis' ? [] : ['hint' => $h];
        $lag = 0;
        $pending = 0;
        try {
            $queue = Queue::connection();
            foreach (self::QUEUES as $name) {
                $pending += $queue->size($name);
                $created = $queue->creationTimeOfOldestPendingJob($name);
                $lag = max($lag, $created ? time() - (int) $created : 0);
            }
            $checks[] = ['label' => 'Umur job tertua', 'value' => $lag.' detik', 'ok' => $lag < 60] + $hint('Tertunda > 1 menit rutin → pasang Redis');
            $checks[] = ['label' => 'Job menunggu', 'value' => (string) $pending, 'ok' => $pending < 1000];
        } catch (Throwable $e) {
            $checks[] = ['label' => 'Antrean ('.config('queue.default').')', 'value' => mb_substr($e->getMessage(), 0, 120), 'ok' => false];
        }
        $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
        $checks[] = ['label' => 'Job gagal 24 jam', 'value' => (string) $failed, 'ok' => $failed === 0];

        $taps = DB::table('taps')->where('created_at', '>=', now()->subDay())->count();
        $checks[] = ['label' => 'Tap 24 jam', 'value' => number_format($taps, 0, ',', '.'), 'ok' => $taps < 50000] + $hint('> ±50.000/hari → pasang Redis');

        $online = 0;
        if (config('session.driver') === 'database') {
            $online = DB::table('sessions')->where('last_activity', '>=', time() - 300)->whereNotNull('user_id')->count();
            $checks[] = ['label' => 'Pengguna online (5 menit)', 'value' => (string) $online, 'ok' => $online < 200] + $hint('> ±200 → pasang Redis');
        }

        // ukuran tabel hanya untuk yang memang memakai database
        $tables = array_keys(array_filter([
            'cache' => config('cache.default') === 'database',
            'sessions' => config('session.driver') === 'database',
            'jobs' => config('queue.default') === 'database',
        ]));
        foreach ($tables as $t) {
            $size = DB::selectOne('select pg_total_relation_size(?) as s', [$t])->s ?? 0;
            $checks[] = ['label' => "Ukuran tabel $t", 'value' => self::bytes((int) $size), 'ok' => $size < 512 * 1024 * 1024];
        }

        if ($onRedis) {
            try {
                $mem = Redis::connection()->client()->info('memory');
                $used = (int) ($mem['used_memory'] ?? 0);
                $max = (int) ($mem['maxmemory'] ?? 0);
                $checks[] = ['label' => 'Memori Redis', 'value' => self::bytes($used).($max ? ' dari '.self::bytes($max) : ''),
                    'ok' => ! $max || $used < $max * 0.9, 'hint' => 'Hampir penuh → naikkan maxmemory di /etc/redis/redis.conf'];
            } catch (Throwable $e) {
                $checks[] = ['label' => 'Koneksi Redis', 'value' => mb_substr($e->getMessage(), 0, 120), 'ok' => false];
            }
        }

        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());
        if ($free !== false && $total) {
            $pct = round($free / $total * 100);
            $checks[] = ['label' => 'Ruang disk tersisa', 'value' => self::bytes((int) $free)." ($pct%)", 'ok' => $pct > 10];
        }

        $redis = config('queue.default') !== 'redis' && ($lag >= 60 || $taps >= 50000 || $online >= 200);

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
