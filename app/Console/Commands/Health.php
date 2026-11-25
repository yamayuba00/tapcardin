<?php

namespace App\Console\Commands;

use App\Services\Payment\PaymentService;
use App\Support\ErrorRate;
use App\Support\Shipping\ShippingProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Throwable;

/** TRD §14 — `tapcard:health`: queue lag, job gagal, ukuran tabel, DB, disk, dan ambang Redis (TRD §3.2). */
class Health extends Command
{
    /** Antrean yang dikerjakan worker (deploy/supervisor/tapcard.conf). */
    private const QUEUES = ['taps', 'notify', 'leads', 'default', 'render', 'broadcast', 'webhooks'];

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

    /** @return array{checks: list<array{label:string,value:string,ok:bool,hint?:string,advisory?:bool}>, redis_recommended: bool} */
    public static function collect(): array
    {
        $checks = [];
        try {
            DB::select('select 1');
            $checks[] = ['label' => 'Koneksi database', 'value' => 'terhubung', 'ok' => true];
        } catch (Throwable $e) {
            return ['checks' => [['label' => 'Koneksi database', 'value' => $e->getMessage(), 'ok' => false]], 'redis_recommended' => false];
        }
        // simulator di luar local/testing = pembayaran mati (gagal tertutup) — harus terlihat di panel & alarm
        $driver = (string) config('payment.driver');
        $payOk = $driver === 'link' || ($driver === 'simulator' && PaymentService::simulatorAllowed());
        $checks[] = ['label' => 'Gateway pembayaran', 'value' => $payOk ? $driver : "driver “{$driver}” tidak berlaku di env ".app()->environment(), 'ok' => $payOk];

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

        $apiKey = (string) config('services.tap_api.key');
        $checks[] = $apiKey !== ''
            ? ['label' => 'Kunci API', 'value' => 'Terpasang', 'ok' => true]
            : (app()->environment('production')
                ? ['label' => 'Kunci API', 'value' => 'Kunci API belum diatur', 'ok' => false]
                : ['label' => 'Kunci API', 'value' => 'Belum diatur (lokal)', 'ok' => true, 'advisory' => true]);

        $taps = DB::table('taps')->where('created_at', '>=', now()->subDay())->count();
        // `advisory` = saran kapasitas, bukan kerusakan → tidak memicu alarm email (tapcard:alert)
        // tarif & resi masih simulasi: di produksi ini harus ditandai (pesanan kurir tidak menerima tarif dummy)
        $shipping = app(ShippingProvider::class);
        $checks[] = ['label' => 'Ongkir', 'value' => $shipping->simulated() ? 'simulasi (tarif placeholder)' : 'penyedia sungguhan',
            'ok' => ! ($shipping->simulated() && app()->isProduction())] + $hint('Isi tarif sungguhan & pasang penyedia ongkir');
        $checks[] = ['label' => 'Tap 24 jam', 'value' => number_format($taps, 0, ',', '.'), 'ok' => $taps < 50000, 'advisory' => true] + $hint('> ±50.000/hari → pasang Redis');

        // tap jatuh ke partisi DEFAULT = partisi bulan itu belum dibuat (scheduler mati) — bulan itu tak bisa dipartisi lagi
        $stray = DB::table('taps_default')->limit(1)->count();
        $checks[] = ['label' => 'Partisi tap', 'value' => $stray ? 'Ada tap di partisi DEFAULT' : 'Lengkap', 'ok' => $stray === 0]
            + $hint('Jalankan tapcard:partitions & pastikan scheduler hidup');

        $online = 0;
        if (config('session.driver') === 'database') {
            $online = DB::table('sessions')->where('last_activity', '>=', time() - 300)->whereNotNull('user_id')->count();
            $checks[] = ['label' => 'Pengguna online (5 menit)', 'value' => (string) $online, 'ok' => $online < 200, 'advisory' => true] + $hint('> ±200 → pasang Redis');
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

        $errors = ErrorRate::lastMinutes(15);
        $last = ErrorRate::last();
        $checks[] = ['label' => 'Error server 15 menit', 'value' => $errors.($errors && $last ? " (terakhir: {$last['class']} — {$last['message']})" : ''),
            'ok' => $errors < (int) config('tapcard.alert.errors_15m'), 'hint' => 'Lihat storage/logs/laravel.log'];

        if (config('tapcard.backup.disk')) {
            $b = rescue(fn () => Cache::get('backup:last'), null, report: false);
            $age = $b ? (int) round((now()->timestamp - $b['at']) / 3600) : null;
            $checks[] = ['label' => 'Backup database terakhir', 'value' => $b ? "$age jam lalu (".self::bytes($b['size']).')' : 'belum pernah',
                'ok' => $age !== null && $age < 26, 'hint' => 'Jalankan php artisan tapcard:backup dan periksa errornya'];
        }

        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());
        if ($free !== false && $total) {
            $pct = round($free / $total * 100);
            $checks[] = ['label' => 'Ruang disk tersisa', 'value' => self::bytes((int) $free)." ($pct%)", 'ok' => $pct > 10];
        }

        foreach (self::envChecks() as $c) {
            $checks[] = $c;
        }

        $redis = config('queue.default') !== 'redis' && ($lag >= 60 || $taps >= 50000 || $online >= 200);

        return ['checks' => $checks, 'redis_recommended' => $redis, 'drivers' => [
            'cache' => config('cache.default'), 'queue' => config('queue.default'), 'session' => config('session.driver'),
        ]];
    }

    /**
     * Pengaturan lingkungan yang wajib benar di produksi. Kunci API, gateway pembayaran & ongkir sudah diperiksa di atas.
     *
     * @return list<array{label:string,value:string,ok:bool,hint?:string,advisory?:bool}>
     */
    private static function envChecks(): array
    {
        $prod = app()->isProduction();
        $checks = [];

        // pesan galat Laravel memuat jejak & variabel konfigurasi bila debug menyala
        $debug = (bool) config('app.debug');
        $checks[] = ['label' => 'Mode debug (APP_DEBUG)', 'value' => $debug ? 'aktif' : 'mati', 'ok' => ! ($prod && $debug)]
            + ($prod ? ['hint' => 'Set APP_DEBUG=false di .env produksi'] : []);

        // log/array = surat tidak terkirim ke siapa pun
        $mailer = (string) config('mail.default');
        $mailOk = ! ($prod && in_array($mailer, ['log', 'array'], true));
        $checks[] = ['label' => 'Surat elektronik', 'value' => $mailer, 'ok' => $mailOk]
            + ($mailOk ? [] : ['hint' => 'Atur MAIL_MAILER ke SMTP atau penyedia surat sungguhan']);

        // domain aplikasi harus berada di bawah domain dasar TapCard (subdomain tenant & panel bergantung padanya)
        $base = strtolower((string) config('tapcard.base_domain'));
        $host = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $hostOk = $host !== '' && $base !== '' && ($host === $base || str_ends_with($host, '.'.$base));
        $checks[] = ['label' => 'Domain aplikasi', 'value' => $host ?: '(APP_URL kosong)', 'ok' => $hostOk, 'advisory' => true]
            + ($hostOk ? [] : ['hint' => "APP_URL harus berada di bawah TAPCARD_BASE_DOMAIN ({$base})"]);

        return $checks;
    }

    private static function bytes(int $b): string
    {
        $u = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = $b > 0 ? (int) floor(log($b, 1024)) : 0;

        return round($b / 1024 ** $i, 1).' '.$u[$i];
    }
}
