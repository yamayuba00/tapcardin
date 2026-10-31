<?php

namespace App\Support\Design;

/**
 * DBD §3.1 — palet primitif tertutup untuk kartu cetak. Template global hanya memakai warna ini;
 * pelanggan boleh menambah hex solid kustom (DBD §8.4). Sumber tunggal untuk frontend (lewat API).
 */
final class Palette
{
    public const COLORS = [
        'onyx-950' => '#0D0D0E', 'onyx-900' => '#151517', 'onyx-800' => '#1F1F22', 'onyx-700' => '#2C2C30',
        'ash-400' => '#9B978F',
        'bone-0' => '#FBF9F4', 'bone-50' => '#F3EFE6', 'bone-100' => '#EAE4D6', 'bone-200' => '#D8D1C2',
        'ink-950' => '#141210', 'umber-600' => '#5B4F36',
        'champagne-300' => '#E2CFA6', 'champagne-500' => '#C9A96E', 'bronze-700' => '#7D6230',
        'sage-300' => '#7FB8A4', 'sage-700' => '#2F6E58',
        'amber-300' => '#E0A458', 'amber-700' => '#8A5A12',
        'coral-300' => '#E07A6A', 'coral-700' => '#A63A2B',
        'sky-300' => '#8FB2D9', 'sky-700' => '#2F5C8F',
    ];

    public static function hex(string $name): string
    {
        return self::COLORS[$name];
    }

    public static function isHex(mixed $v): bool
    {
        return is_string($v) && (bool) preg_match('/^#[0-9A-Fa-f]{6}$/', $v);
    }

    /** Luminans relatif WCAG. */
    public static function luminance(string $hex): float
    {
        $c = array_map(fn ($h) => hexdec($h) / 255, str_split(ltrim($hex, '#'), 2));
        $c = array_map(fn ($v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    public static function contrast(string $a, string $b): float
    {
        [$l1, $l2] = [self::luminance($a), self::luminance($b)];

        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }

    /** Warna tinta untuk elemen sistem di atas latar: ink-950 atau bone-50, mana yang kontrasnya lebih tinggi. */
    public static function inkOn(string $background): string
    {
        return self::contrast(self::hex('ink-950'), $background) >= self::contrast(self::hex('bone-50'), $background)
            ? self::hex('ink-950') : self::hex('bone-50');
    }
}
