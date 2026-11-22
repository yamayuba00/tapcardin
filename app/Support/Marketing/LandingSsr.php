<?php

namespace App\Support\Marketing;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * HTML landing page hasil render server (Vue SSR) agar mesin pencari & tampilan pertama tidak menunggu JS.
 * Bundle dibangun `npm run build` (bootstrap/ssr/landing.js) dan dijalankan dengan node hanya saat cache kosong;
 * hasil di-cache per isi data + versi build. Gagal (node tidak ada, error, lambat) → null, halaman tetap SPA biasa.
 */
final class LandingSsr
{
    public static function bundle(): string
    {
        return base_path('bootstrap/ssr/landing.js');
    }

    /** @param  array  $payload  {config, initial} — sama persis dengan yang dipakai klien saat mount */
    public static function render(array $payload): ?string
    {
        $bundle = self::bundle();
        if (! config('tapcard.ssr.enabled') || ! is_file($bundle)) {
            return null;
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $key = 'landing:ssr:'.md5($json.'|'.filemtime($bundle));
        if (($html = Cache::get($key)) !== null) {
            return $html ?: null;
        }
        // satu proses render sekaligus; permintaan lain dilayani SPA biasa sampai cache terisi
        $lock = Cache::lock('landing:ssr:lock', 15);
        if (! $lock->get()) {
            return null;
        }
        try {
            try {
                $html = self::run($bundle, $json);
            } catch (\Throwable $e) { // node tidak ada / gagal dijalankan → halaman tetap jalan sebagai SPA
                Log::warning('SSR landing gagal', ['error' => mb_substr($e->getMessage(), 0, 300)]);
                $html = null;
            }
            Cache::put($key, $html ?? '', $html ? now()->addDay() : now()->addMinutes(5));

            return $html;
        } finally {
            $lock->release();
        }
    }

    private static function run(string $bundle, string $json): ?string
    {
        $proc = proc_open([config('tapcard.ssr.node'), $bundle], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        if (! is_resource($proc)) {
            return null;
        }
        fwrite($pipes[0], $json);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = $err = '';
        $deadline = microtime(true) + (float) config('tapcard.ssr.timeout');
        while (microtime(true) < $deadline) {
            $out .= stream_get_contents($pipes[1]);
            $err .= stream_get_contents($pipes[2]);
            if (! proc_get_status($proc)['running']) {
                break;
            }
            usleep(20_000);
        }
        $out .= stream_get_contents($pipes[1]);
        $err .= stream_get_contents($pipes[2]);
        $running = proc_get_status($proc)['running'];
        if ($running) {
            proc_terminate($proc);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($running || $code !== 0 || ! str_starts_with(ltrim($out), '<')) {
            Log::warning('SSR landing gagal', ['timeout' => $running, 'code' => $code, 'stderr' => mb_substr($err, 0, 500)]);

            return null;
        }

        return $out;
    }
}
