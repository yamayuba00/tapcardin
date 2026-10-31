<?php

namespace App\Support\Bio;

use App\Support\Design\Palette;

/**
 * Tema bio link per kartu. Yang disimpan hanya pilihan pengguna (preset + opsi); warna akhir
 * di-resolve di sini menjadi NAMA token palet DBD (bukan hex) agar frontend cukup memetakan
 * ke `var(--color-*)`. Kontras aksen dijaga server sehingga pilihan apa pun tetap terbaca.
 */
final class BioTheme
{
    /** Preset selaras dengan template kartu (TemplateLibrary). Kunci = variabel CSS semantik di tokens.css. */
    public const PRESETS = [
        'onyx' => ['Onyx', 'dark', ['bg' => 'onyx-950', 'surface-1' => 'onyx-900', 'surface-2' => 'onyx-800', 'line' => 'onyx-700', 'line-strong' => 'ash-400', 'text' => 'bone-50', 'text-2' => 'ash-400'], 'champagne-500'],
        'ivory' => ['Ivory', 'light', ['bg' => 'bone-50', 'surface-1' => 'bone-0', 'surface-2' => 'bone-100', 'line' => 'bone-200', 'line-strong' => 'umber-600', 'text' => 'ink-950', 'text-2' => 'umber-600'], 'champagne-500'],
        'garis' => ['Garis', 'light', ['bg' => 'bone-0', 'surface-1' => 'bone-50', 'surface-2' => 'bone-100', 'line' => 'bone-200', 'line-strong' => 'ink-950', 'text' => 'ink-950', 'text-2' => 'umber-600'], 'ink-950'],
        'kreatif' => ['Kreatif', 'dark', ['bg' => 'onyx-950', 'surface-1' => 'onyx-900', 'surface-2' => 'onyx-800', 'line' => 'onyx-700', 'line-strong' => 'ash-400', 'text' => 'bone-50', 'text-2' => 'ash-400'], 'coral-300'],
        'natural' => ['Natural', 'light', ['bg' => 'bone-100', 'surface-1' => 'bone-50', 'surface-2' => 'bone-200', 'line' => 'bone-200', 'line-strong' => 'umber-600', 'text' => 'ink-950', 'text-2' => 'umber-600'], 'umber-600'],
        'metal' => ['Metal', 'dark', ['bg' => 'onyx-800', 'surface-1' => 'onyx-900', 'surface-2' => 'onyx-700', 'line' => 'onyx-700', 'line-strong' => 'ash-400', 'text' => 'bone-100', 'text-2' => 'ash-400'], 'bone-200'],
        'sage' => ['Sage', 'light', ['bg' => 'bone-50', 'surface-1' => 'bone-0', 'surface-2' => 'bone-100', 'line' => 'bone-200', 'line-strong' => 'umber-600', 'text' => 'ink-950', 'text-2' => 'umber-600'], 'sage-700'],
        'korporat' => ['Korporat', 'light', ['bg' => 'bone-0', 'surface-1' => 'bone-50', 'surface-2' => 'bone-100', 'line' => 'bone-200', 'line-strong' => 'umber-600', 'text' => 'ink-950', 'text-2' => 'umber-600'], 'sky-700'],
    ];

    public const ACCENTS = [
        'champagne-500', 'champagne-300', 'bronze-700', 'umber-600', 'sage-300', 'sage-700', 'sky-300', 'sky-700',
        'coral-300', 'coral-700', 'amber-300', 'amber-700', 'bone-200', 'ash-400', 'ink-950',
    ];

    /** Opsi tata letak: kunci => [nilai yang diizinkan], nilai pertama = bawaan. */
    public const OPTIONS = [
        'header' => ['center', 'left', 'cover'],
        'avatar' => ['circle', 'rounded', 'square'],
        'corners' => ['rounded', 'sharp', 'pill'],
        'button' => ['solid', 'outline'],
        'links' => ['list', 'buttons', 'grid'],
        'font' => ['Syne', 'IBM Plex Sans', 'IBM Plex Mono'],
        'decoration' => ['rings', 'none'],
    ];

    /** Rapikan masukan pengguna / data lama (`template`) menjadi pilihan yang valid. */
    public static function normalize(?array $in): array
    {
        $in ??= [];
        $preset = $in['preset'] ?? $in['template'] ?? 'onyx';
        $out = ['preset' => isset(self::PRESETS[$preset]) ? $preset : 'onyx'];
        $accent = $in['accent'] ?? null;
        if (in_array($accent, self::ACCENTS, true) && $accent !== self::PRESETS[$out['preset']][3]) {
            $out['accent'] = $accent; // simpan hanya bila beda dari bawaan preset
        }
        foreach (self::OPTIONS as $key => $allowed) {
            $out[$key] = in_array($in[$key] ?? null, $allowed, true) ? $in[$key] : $allowed[0];
        }

        return $out;
    }

    /** Pilihan + warna akhir untuk dirender. @return array<string, mixed> */
    public static function resolve(?array $in): array
    {
        $t = self::normalize($in);
        [, $mode, $vars, $presetAccent] = self::PRESETS[$t['preset']];
        $bg = Palette::hex($vars['bg']);

        // aksen yang hampir sama dengan latar (mis. tinta di atas onyx) tidak terlihat sebagai tombol → pakai warna teks
        $accent = $t['accent'] ?? $presetAccent;
        if (Palette::contrast(Palette::hex($accent), $bg) < 1.6) {
            $accent = $vars['text'];
        }
        $vars['accent'] = $accent;
        $vars['accent-ink'] = self::name(Palette::inkOn(Palette::hex($accent)));
        // teks berwarna aksen butuh kontras ≥ 3:1 (teks besar/ikon); bila kurang, jatuh ke warna teks
        $vars['accent-text'] = Palette::contrast(Palette::hex($accent), $bg) >= 3 ? $accent : $vars['text'];
        $vars['accent-soft'] = $vars['surface-2'];

        return $t + ['mode' => $mode, 'vars' => $vars];
    }

    /** Katalog untuk editor & landing page. */
    public static function catalog(): array
    {
        return [
            // warna aksen sudah di-resolve per preset agar editor bisa preview langsung tanpa request
            'presets' => array_map(fn ($k) => ['key' => $k, 'name' => self::PRESETS[$k][0]] + self::resolve(['preset' => $k]) + [
                'accent_vars' => array_combine(self::ACCENTS, array_map(
                    fn ($a) => array_intersect_key(self::resolve(['preset' => $k, 'accent' => $a])['vars'], array_flip(['accent', 'accent-ink', 'accent-text'])),
                    self::ACCENTS,
                )),
            ], array_keys(self::PRESETS)),
            'accents' => self::ACCENTS,
            'options' => self::OPTIONS,
        ];
    }

    private static function name(string $hex): string
    {
        return array_search(strtoupper($hex), array_map('strtoupper', Palette::COLORS), true) ?: 'ink-950';
    }
}
