<?php

namespace App\Support;

/**
 * Crockford base32 untuk kode yang diketik manusia: kode pendek (BSD cards.short_code)
 * dan kode aktivasi (TRD §11). Tanpa I, L, O, U agar tidak tertukar.
 */
final class Crockford
{
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function random(int $length = 8): string
    {
        return Base62::randomFrom(self::ALPHABET, $length);
    }

    /** Normalisasi input pengguna: huruf besar, buang spasi/tanda hubung/prefix, petakan huruf mirip. */
    public static function normalize(string $input): string
    {
        $s = strtoupper(trim($input));
        $s = preg_replace('/^TC[·.\s-]*/u', '', $s);
        $s = preg_replace('/[\s\-·.]/u', '', $s);

        return strtr($s, ['O' => '0', 'I' => '1', 'L' => '1']);
    }

    public static function isValid(string $code, int $length = 8): bool
    {
        return strlen($code) === $length && strspn($code, self::ALPHABET) === $length;
    }
}
