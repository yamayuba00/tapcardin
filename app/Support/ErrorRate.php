<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Hitungan error server (exception yang dilaporkan: 500 di web/API, job & perintah yang gagal) per menit di cache,
 * dibaca `tapcard:health` → alarm email bila melonjak. Error bisnis (ApiError), validasi, dan 4xx tidak dihitung
 * karena memang tidak dilaporkan.
 */
final class ErrorRate
{
    public static function hit(Throwable $e): void
    {
        // gagal mencatat (mis. cache ikut mati) tidak boleh menimbulkan error baru
        rescue(function () use ($e) {
            $key = 'errors:'.intdiv(now()->timestamp, 60);
            Cache::add($key, 0, 7200);
            Cache::increment($key);
            Cache::put('errors:last', ['class' => class_basename($e), 'message' => mb_substr($e->getMessage(), 0, 160), 'at' => now()->toIso8601String()], 86400);
        }, report: false);
    }

    /** Jumlah error dalam N menit terakhir. */
    public static function lastMinutes(int $minutes): int
    {
        $now = intdiv(now()->timestamp, 60);
        $keys = array_map(fn ($i) => 'errors:'.($now - $i), range(0, $minutes - 1));

        // cache mati → 0 (pemeriksaan cache/Redis sendiri yang melaporkannya)
        return (int) rescue(fn () => array_sum(array_map('intval', Cache::many($keys))), 0, report: false);
    }

    /** @return array{class:string,message:string,at:string}|null */
    public static function last(): ?array
    {
        return rescue(fn () => Cache::get('errors:last'), null, report: false);
    }
}
