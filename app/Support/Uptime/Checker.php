<?php

namespace App\Support\Uptime;

use App\Events\MonitorChecked;
use App\Events\StatusPageUpdated;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorEvent;
use App\Support\PlatformAlerts;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Menjalankan cek monitor & mengelola status:
 *  - HTTP: kode status sesuai `expected_status` (+ kata kunci di isi bila diisi), paralel lewat Http::pool;
 *  - SSL: sisa masa berlaku sertifikat — ≤ ssl_warn_days → degraded, kedaluwarsa / tidak valid → down;
 *  - gagal harus terjadi `retries + 1` kali berturut-turut sebelum down (menghindari alarm palsu jaringan sesaat);
 *  - perubahan status → monitor_events, email ke Pemilik Platform, siaran realtime (panel & halaman status).
 */
final class Checker
{
    /** @param  Collection<int,Monitor>  $monitors */
    public function run(Collection $monitors): void
    {
        $http = $monitors->where('type', 'http')->values();
        if ($http->isNotEmpty()) {
            $responses = Http::pool(fn (Pool $pool) => $http->map(fn (Monitor $m) => $pool->as((string) $m->id)
                ->timeout($m->timeout_seconds)->connectTimeout(min(5, $m->timeout_seconds))
                ->withUserAgent('TapCard-Uptime/1.0')->withOptions(['allow_redirects' => ['max' => 5]])
                ->send($m->method, $m->url))->all());
            foreach ($http as $m) {
                $this->record($m, ...self::evaluateHttp($m, $responses[(string) $m->id] ?? null));
            }
        }
        foreach ($monitors->where('type', 'ssl') as $m) {
            $this->record($m, ...self::evaluateSsl($m));
        }
    }

    /** @return array{0:string,1:?int,2:?int,3:?string,4:?int} status, ms, http code, error, sertifikat (timestamp) */
    public static function evaluateHttp(Monitor $m, mixed $r): array
    {
        if (! $r instanceof Response) {
            $msg = $r instanceof Throwable ? $r->getMessage() : 'Tidak ada jawaban';

            return ['down', null, null, self::connectionError($msg, $m->timeout_seconds), null];
        }
        $ms = (int) round((($r->handlerStats()['total_time'] ?? 0)) * 1000) ?: null;
        $code = $r->status();
        if (! self::statusMatches($code, $m->expected_status)) {
            return ['down', $ms, $code, "Kode $code (diharapkan {$m->expected_status})", null];
        }
        if ($m->keyword && ! str_contains(mb_strtolower($r->body()), mb_strtolower($m->keyword))) {
            return ['down', $ms, $code, "Kata kunci “{$m->keyword}” tidak ditemukan", null];
        }

        return ['up', $ms, $code, null, null];
    }

    /** @return array{0:string,1:?int,2:?int,3:?string,4:?int} */
    public static function evaluateSsl(Monitor $m): array
    {
        $host = parse_url($m->url, PHP_URL_HOST) ?: $m->url;
        $port = parse_url($m->url, PHP_URL_PORT) ?: 443;
        $started = microtime(true);
        $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'SNI_enabled' => true, 'peer_name' => $host, 'verify_peer' => true, 'verify_peer_name' => true]]);
        $fp = @stream_socket_client("ssl://$host:$port", $errno, $errstr, $m->timeout_seconds, STREAM_CLIENT_CONNECT, $ctx);
        $ms = (int) round((microtime(true) - $started) * 1000);
        if (! $fp) {
            return ['down', null, null, 'Sertifikat tidak valid atau tidak terjangkau: '.mb_substr($errstr ?: error_get_last()['message'] ?? 'gagal', 0, 200), null];
        }
        $cert = openssl_x509_parse(stream_context_get_params($fp)['options']['ssl']['peer_certificate'] ?? '');
        fclose($fp);
        $expires = (int) ($cert['validTo_time_t'] ?? 0);
        if (! $expires) {
            return ['down', $ms, null, 'Sertifikat tidak terbaca', null];
        }
        $days = (int) floor(($expires - now()->timestamp) / 86400);

        return match (true) {
            $days < 0 => ['down', $ms, null, 'Sertifikat sudah kedaluwarsa', $expires],
            $days <= $m->ssl_warn_days => ['degraded', $ms, null, "Sertifikat habis dalam $days hari", $expires],
            default => ['up', $ms, null, null, $expires],
        };
    }

    /** "200-399" atau "200,301,302" (boleh campur). */
    public static function statusMatches(int $code, string $expected): bool
    {
        foreach (explode(',', $expected) as $part) {
            $part = trim($part);
            if (str_contains($part, '-')) {
                [$a, $b] = array_map('intval', explode('-', $part, 2));
                if ($code >= $a && $code <= $b) {
                    return true;
                }
            } elseif ($part !== '' && (int) $part === $code) {
                return true;
            }
        }

        return false;
    }

    private static function connectionError(string $msg, int $timeout): string
    {
        return match (true) {
            str_contains($msg, 'timed out') || str_contains($msg, 'Timeout') => "Tidak menjawab dalam $timeout detik",
            str_contains($msg, 'Could not resolve') => 'Domain tidak ditemukan (DNS)',
            str_contains($msg, 'Connection refused') => 'Koneksi ditolak',
            str_contains($msg, 'SSL') || str_contains($msg, 'certificate') => 'Masalah SSL: '.mb_substr($msg, 0, 160),
            default => 'Gagal terhubung: '.mb_substr($msg, 0, 160),
        };
    }

    /** Simpan hasil, hitung status baru, dan beri tahu bila berubah. */
    public function record(Monitor $m, string $raw, ?int $ms, ?int $code, ?string $error, ?int $sslExpires = null): void
    {
        $streak = $raw === 'down' ? $m->fail_streak + 1 : 0;
        // status baru: down hanya setelah retries+1 kali gagal; sebelumnya status lama dipertahankan
        $next = match (true) {
            $raw !== 'down' => $raw,
            $streak > $m->retries || $m->status === 'down' => 'down',
            default => $m->status === 'pending' ? 'pending' : $m->status,
        };
        $from = $m->status;
        $changed = $next !== $from && ! ($from === 'pending' && $next === 'pending');

        DB::transaction(function () use ($m, $raw, $ms, $code, $error, $sslExpires, $streak, $next, $from, $changed) {
            MonitorCheck::create(['monitor_id' => $m->id, 'status' => $raw, 'response_ms' => $ms, 'http_status' => $code,
                'error' => $error ? mb_substr($error, 0, 300) : null, 'checked_at' => now()]);
            $m->update([
                'status' => $next, 'fail_streak' => min($streak, 1000), 'last_checked_at' => now(),
                'next_check_at' => now()->addSeconds($m->interval_seconds), 'last_response_ms' => $ms, 'last_http_status' => $code,
                'last_error' => $error ? mb_substr($error, 0, 300) : null, 'ssl_expires_at' => $sslExpires ? now()->setTimestamp($sslExpires) : $m->ssl_expires_at,
            ] + ($changed ? ['last_change_at' => now()] : []));
            if ($changed) {
                MonitorEvent::create(['monitor_id' => $m->id, 'from_status' => $from, 'to_status' => $next, 'reason' => $error, 'created_at' => now()]);
            }
        });

        $change = $changed ? ['from' => $from, 'to' => $next, 'reason' => $error] : null;
        rescue(fn () => broadcast(new MonitorChecked(Uptime::presentMonitor($m->fresh()),
            ['status' => $raw, 'response_ms' => $ms, 'http_status' => $code, 'error' => $error, 'at' => now()->toIso8601String()], $change)), report: false);
        if ($changed) {
            $this->notify($m, $from, $next, $error);
        }
    }

    private function notify(Monitor $m, string $from, string $to, ?string $reason): void
    {
        Cache::forget(StatusPage::CACHE_KEY);
        if ($m->is_public) {
            rescue(fn () => broadcast(new StatusPageUpdated), report: false);
        }
        // pending → up tidak perlu dikabari; selain itu setiap perubahan dikirim
        $type = match (true) {
            $to === 'down' => 'monitor_down',
            $to === 'degraded' => 'monitor_degraded',
            $to === 'up' && in_array($from, ['down', 'degraded'], true) => 'monitor_up',
            default => null,
        };
        if ($type) {
            // sejak kapan status sebelumnya berlangsung (untuk "pulih setelah X menit")
            $since = MonitorEvent::where('monitor_id', $m->id)->where('to_status', $from)->latest('id')->first()?->created_at;
            PlatformAlerts::email($type, [
                'name' => $m->name, 'url' => PlatformAlerts::panelUrl('/uptime'), 'target' => $m->url, 'reason' => $reason,
                'since' => $since?->toIso8601String(),
            ]);
        }
    }
}
