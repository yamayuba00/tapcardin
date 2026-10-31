<?php

namespace App\Support\Qr;

use InvalidArgumentException;

/**
 * Encoder QR PHP murni (TRD §8.1). Cakupan sengaja dibatasi: mode byte, level koreksi M,
 * versi 2–6 (satu pola alignment), dipilih otomatis. URL kartu (~42 byte) jatuh di versi 4.
 */
final class QrEncoder
{
    /** versi => [ec per blok, jumlah blok, data per blok, pusat alignment] — level M */
    private const VERSIONS = [
        2 => [16, 1, 28, 18],
        3 => [26, 1, 44, 22],
        4 => [18, 2, 32, 26],
        5 => [24, 2, 43, 30],
        6 => [16, 4, 27, 34],
    ];

    /** Bit level koreksi M di info format. */
    private const EC_BITS_M = 0b00;

    public static function encode(string $data, ?int $forceMask = null): Matrix
    {
        $version = self::pickVersion(strlen($data));
        [$ecLen, $blocks, $dataPerBlock, $align] = self::VERSIONS[$version];

        $codewords = self::interleave(self::dataCodewords($data, $blocks * $dataPerBlock), $blocks, $dataPerBlock, $ecLen);

        $base = new Matrix($version);
        $base->drawFunctionPatterns($align);
        $base->drawCodewords($codewords);

        $best = null;
        $bestScore = PHP_INT_MAX;
        foreach ($forceMask !== null ? [$forceMask] : range(0, 7) as $mask) {
            $candidate = clone $base;
            $candidate->applyMask($mask);
            $candidate->drawFormatBits(self::EC_BITS_M, $mask);
            $score = MaskEvaluator::penalty($candidate);
            if ($score < $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $best;
    }

    public static function pickVersion(int $byteLength): int
    {
        foreach (self::VERSIONS as $v => [, $blocks, $dataPerBlock]) {
            // mode (4) + jumlah karakter (8 bit untuk versi 1–9) + data
            if (4 + 8 + $byteLength * 8 <= $blocks * $dataPerBlock * 8) {
                return $v;
            }
        }
        throw new InvalidArgumentException("Data $byteLength byte melebihi kapasitas QR versi 6-M.");
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
     * Bagi data per blok, hitung EC, lalu interleave kolom demi kolom (semua blok berukuran sama di V2–V6 M).
     *
     * @param  int[]  $data
     * @return int[]
     */
    private static function interleave(array $data, int $blocks, int $dataPerBlock, int $ecLen): array
    {
        $dataBlocks = array_chunk($data, $dataPerBlock);
        $ecBlocks = array_map(fn ($b) => ReedSolomon::remainder($b, $ecLen), $dataBlocks);
        $out = [];
        for ($i = 0; $i < $dataPerBlock; $i++) {
            foreach ($dataBlocks as $b) {
                $out[] = $b[$i];
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
