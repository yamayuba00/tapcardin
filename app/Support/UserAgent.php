<?php

namespace App\Support;

/** Hanya keluarga perangkat & browser yang disimpan (TRD §11). */
final class UserAgent
{
    public static function device(?string $ua): string
    {
        $ua = (string) $ua;

        return match (true) {
            (bool) preg_match('/iPhone|iPad|iPod/i', $ua) => 'ios',
            (bool) preg_match('/Android/i', $ua) => 'android',
            (bool) preg_match('/Windows|Macintosh|Linux|CrOS/i', $ua) => 'desktop',
            default => 'other',
        };
    }

    public static function browser(?string $ua): ?string
    {
        $ua = (string) $ua;

        return match (true) {
            str_contains($ua, 'Edg/') => 'edge',
            str_contains($ua, 'SamsungBrowser') => 'samsung',
            str_contains($ua, 'CriOS') || str_contains($ua, 'Chrome/') => 'chrome',
            str_contains($ua, 'FxiOS') || str_contains($ua, 'Firefox/') => 'firefox',
            str_contains($ua, 'Safari/') => 'safari',
            $ua === '' => null,
            default => 'other',
        };
    }
}
