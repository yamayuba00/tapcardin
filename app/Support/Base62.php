<?php

namespace App\Support;

/** TRD §6.1 — token kartu 10 karakter base62 dari random_bytes. */
final class Base62
{
    public const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public static function random(int $length = 10): string
    {
        return self::randomFrom(self::ALPHABET, $length);
    }

    /** Rejection sampling agar distribusi seragam (tanpa bias modulo). */
    public static function randomFrom(string $alphabet, int $length): string
    {
        $n = strlen($alphabet);
        $limit = 256 - (256 % $n);
        $out = '';
        while (strlen($out) < $length) {
            foreach (unpack('C*', random_bytes($length * 2)) as $byte) {
                if ($byte < $limit) {
                    $out .= $alphabet[$byte % $n];
                    if (strlen($out) === $length) {
                        break;
                    }
                }
            }
        }

        return $out;
    }
}
