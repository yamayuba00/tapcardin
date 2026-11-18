<?php

namespace App\Support\Design;

/**
 * Tata letak teks perkiraan (mm) untuk validasi cetak — sama persis dengan resources/js/lib/design.js
 * (textWidth, wrapText, fitTextBox, curveBox) agar editor dan server memberi hasil yang sama.
 */
final class TextLayout
{
    public const PT_MM = 0.3528;

    public const MIN_PT = 6.0;

    public static function width(array $l, string $text, float $h): float
    {
        return mb_strlen($text) * $h * Fonts::width($l['font'] ?? null) * (1 + ($l['tracking'] ?? 0));
    }

    public static function isBox(array $l): bool
    {
        return ($l['type'] ?? null) === 'text' && ! empty($l['box']) && ($l['w'] ?? 0) > 0 && ($l['h'] ?? 0) > 0;
    }

    public static function isCurved(array $l): bool
    {
        return ($l['type'] ?? null) === 'text' && in_array($l['curve'] ?? null, ['up', 'down'], true);
    }

    /** @return list<string> */
    public static function wrap(array $l, string $text, float $maxW, float $h): array
    {
        $out = [];
        foreach (explode("\n", $text) as $para) {
            $line = '';
            foreach (explode(' ', $para) as $word) {
                $cand = $line === '' ? $word : "$line $word";
                if ($line === '' || self::width($l, $cand, $h) <= $maxW + 0.01) {
                    $line = $cand;
                } else {
                    $out[] = $line;
                    $line = $word;
                }
            }
            $out[] = $line;
        }

        return $out;
    }

    /** Kotak teks: dibungkus ke lebar kotak lalu diperkecil 0,5 pt per langkah sampai muat (min 6 pt). @return array{size:float,lines:list<string>,overflow:bool} */
    public static function fitBox(array $l): array
    {
        $text = ! empty($l['uppercase']) ? mb_strtoupper($l['text']) : $l['text'];
        $lh = $l['lineHeight'] ?? 1.25;
        $size = (float) $l['size'];
        while (true) {
            $h = $size * self::PT_MM;
            $lines = self::wrap($l, $text, (float) $l['w'], $h);
            $height = $h + $h * $lh * (count($lines) - 1);
            $widest = max(0, ...array_map(fn ($line) => self::width($l, $line, $h), $lines));
            $fits = $height <= $l['h'] + 0.01 && $widest <= $l['w'] + 0.01;
            if ($fits || $size <= self::MIN_PT) {
                return ['size' => $size, 'lines' => $lines, 'overflow' => ! $fits];
            }
            $size = max(self::MIN_PT, round($size - 0.5, 1));
        }
    }

    /** Kotak pembatas teks melengkung: 17 titik sampel di busur termasuk tinggi huruf, diputar `curveAngle`. */
    public static function curveBox(array $l): array
    {
        $text = str_replace("\n", ' ', $l['text']);
        $h = $l['size'] * self::PT_MM;
        $wid = self::width($l, ! empty($l['uppercase']) ? mb_strtoupper($text) : $text, $h);
        $r = (float) $l['curveR'];
        $up = ($l['curve'] ?? 'up') !== 'down';
        $cx = (float) $l['x'];
        $cy = $up ? $l['y'] + $r : $l['y'] - $r;
        $theta = min(2 * M_PI, $wid / max($r, 0.1));
        $radii = $up ? [$r - $h * 0.2, $r + $h * 0.8] : [$r - $h * 0.8, $r + $h * 0.2];
        $a = deg2rad((float) ($l['curveAngle'] ?? 0));
        $xs = $ys = [];
        for ($k = 0; $k <= 16; $k++) {
            $phi = -$theta / 2 + $k * $theta / 16;
            foreach ($radii as $rr) {
                $px = $cx + $rr * sin($phi);
                $py = $up ? $cy - $rr * cos($phi) : $cy + $rr * cos($phi);
                $xs[] = $cx + ($px - $cx) * cos($a) - ($py - $cy) * sin($a);
                $ys[] = $cy + ($px - $cx) * sin($a) + ($py - $cy) * cos($a);
            }
        }

        return ['x' => min($xs), 'y' => min($ys), 'w' => max($xs) - min($xs), 'h' => max($ys) - min($ys)];
    }
}
