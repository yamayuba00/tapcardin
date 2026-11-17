<?php

namespace App\Support\Qr;

use InvalidArgumentException;

/**
 * Encoder QR PHP murni (TRD §8.1). Cakupan sengaja dibatasi: mode byte, level koreksi M atau H,
 * versi 2–6 (satu pola alignment), dipilih otomatis. URL kartu (~42 byte) jatuh di versi 3-M / 5-H.
 */
final class QrEncoder
{
    /** level => versi => [ec per blok, [[jumlah blok, data per blok], ...], pusat alignment] (ISO/IEC 18004 tabel 9) */
    private const VERSIONS = [
        'M' => [
            2 => [16, [[1, 28]], 18],
            3 => [26, [[1, 44]], 22],
            4 => [18, [[2, 32]], 26],
            5 => [24, [[2, 43]], 30],
            6 => [16, [[4, 27]], 34],
        ],
        'H' => [
            2 => [28, [[1, 16]], 18],
            3 => [22, [[2, 13]], 22],
            4 => [16, [[4, 9]], 26],
            5 => [22, [[2, 11], [2, 12]], 30],
            6 => [28, [[4, 15]], 34],
        ],
    ];

    /** Bit level koreksi di info format. */
    private const EC_BITS = ['M' => 0b00, 'H' => 0b10];

    public static function encode(string $data, ?int $forceMask = null, string $level = 'M'): Matrix
    {
        $version = self::pickVersion(strlen($data), $level);
        [$ecLen, $groups, $align] = self::VERSIONS[$level][$version];
        $sizes = [];
        foreach ($groups as [$count, $len]) {
            array_push($sizes, ...array_fill(0, $count, $len));
        }

        $codewords = self::interleave(self::dataCodewords($data, array_sum($sizes)), $sizes, $ecLen);

        $base = new Matrix($version);
        $base->drawFunctionPatterns($align);
        $base->drawCodewords($codewords);

        $best = null;
        $bestScore = PHP_INT_MAX;
        foreach ($forceMask !== null ? [$forceMask] : range(0, 7) as $mask) {
            $candidate = clone $base;
            $candidate->applyMask($mask);
            $candidate->drawFormatBits(self::EC_BITS[$level], $mask);
            $score = MaskEvaluator::penalty($candidate);
            if ($score < $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $best;
    }

    public static function fits(int $byteLength, string $level): bool
    {
        try {
            self::pickVersion($byteLength, $level);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public static function pickVersion(int $byteLength, string $level = 'M'): int
    {
        foreach (self::VERSIONS[$level] as $v => [, $groups]) {
            $capacity = array_sum(array_map(fn ($g) => $g[0] * $g[1], $groups));
            // mode (4) + jumlah karakter (8 bit untuk versi 1–9) + data
            if (4 + 8 + $byteLength * 8 <= $capacity * 8) {
                return $v;
            }
        }
        throw new InvalidArgumentException("Data $byteLength byte melebihi kapasitas QR versi 6-$level.");
    }

    /** @return int[] */
    private static function dataCodewords(string $data, int $capacity): array
    {
        $bb = new BitBuffer;
        $bb->append(0b0100, 4); // mode byte
        $bb->append(strlen($data), 8);
        foreach (unpack('C*', $data) ?: [] as $byte) {
            $bb->append($byte, 8);
        }
        $capacityBits = $capacity * 8;
        $bb->append(0, min(4, $capacityBits - $bb->length())); // terminator
        $bb->append(0, (8 - $bb->length() % 8) % 8);
        $bytes = $bb->toBytes();
        for ($pad = 0xEC; count($bytes) < $capacity; $pad ^= 0xEC ^ 0x11) {
            $bytes[] = $pad;
        }

        return $bytes;
    }

    /**
     * Bagi data per blok (5-H punya dua ukuran blok), hitung EC, lalu interleave kolom demi kolom;
     * blok yang lebih pendek dilewati di kolom terakhirnya.
     *
     * @param  int[]  $data
     * @param  int[]  $sizes  jumlah data per blok, urut sesuai grup
     * @return int[]
     */
    private static function interleave(array $data, array $sizes, int $ecLen): array
    {
        $dataBlocks = [];
        $offset = 0;
        foreach ($sizes as $len) {
            $dataBlocks[] = array_slice($data, $offset, $len);
            $offset += $len;
        }
        $ecBlocks = array_map(fn ($b) => ReedSolomon::remainder($b, $ecLen), $dataBlocks);
        $out = [];
        for ($i = 0; $i < max($sizes); $i++) {
            foreach ($dataBlocks as $b) {
                if (isset($b[$i])) {
                    $out[] = $b[$i];
                }
            }
        }
        for ($i = 0; $i < $ecLen; $i++) {
            foreach ($ecBlocks as $b) {
                $out[] = $b[$i];
            }
        }

        return $out;
    }
}
