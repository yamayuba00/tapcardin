<?php

namespace App\Support;

/** TRD §10.7 — normalisasi nomor ke E.164 (default Indonesia). */
final class Phone
{
    public static function toE164(?string $raw, string $defaultCountry = '62'): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $plus = str_starts_with(trim($raw), '+');
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === '') {
            return null;
        }
        if (! $plus) {
            if (str_starts_with($digits, '00')) {
                $digits = substr($digits, 2);
            } elseif (str_starts_with($digits, '0')) {
                $digits = $defaultCountry.substr($digits, 1);
            } elseif (! str_starts_with($digits, $defaultCountry)) {
                $digits = $defaultCountry.$digits;
            }
        }
        $len = strlen($digits);
        if ($len < 8 || $len > 15) {
            return null;
        }

        return '+'.$digits;
    }

    /** Nomor untuk tautan wa.me (tanpa +). */
    public static function waDigits(string $e164): string
    {
        return preg_replace('/\D/', '', $e164);
    }

    /** Format lokal untuk ditampilkan/disalin: +6281234567890 → 081234567890. */
    public static function local(string $e164): string
    {
        return str_starts_with($e164, '+62') ? '0'.substr($e164, 3) : $e164;
    }
}
