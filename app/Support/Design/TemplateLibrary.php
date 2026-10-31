<?php

namespace App\Support\Design;

use InvalidArgumentException;

/**
 * 8 template default (DBD §8.4 + 2 tambahan: Garis, Sage). Semua warna dari palet tertutup, bidang solid saja.
 * Setiap template punya tata letak lanskap & potret; elemen sistem (QR, NFC, TapCard) ditambahkan SystemLayers.
 * Variabel: {{name}} {{title}} {{company}} {{phone}} {{email}} {{website}} — diisi dari profil pemegang.
 */
final class TemplateLibrary
{
    public const TEMPLATES = [
        'kosong' => ['Kosong', 'Kanvas putih — hanya QR, NFC, dan TapCard. Desain dari nol.'],
        'onyx' => ['Onyx', 'Hitam matte, bilah champagne, cincin sinyal — kartu referensi brand.'],
        'ivory' => ['Ivory', 'Terang & tenang, nama di tengah, satu garis bronze.'],
        'garis' => ['Garis', 'Tipografi rata kiri dengan satu garis rambut — paling minimal.'],
        'kreatif' => ['Kreatif', 'Nama raksasa champagne terpotong tepi kartu.'],
        'natural' => ['Natural', 'Nuansa kayu: umber hangat dengan teks gading.'],
        'metal' => ['Metal', 'Grafit gelap dengan cincin etsa.'],
        'sage' => ['Sage', 'Hijau sage solid, aksen garis pendek.'],
        'korporat' => ['Korporat', 'Pita warna merek di atas, cocok untuk tim.'],
    ];

    /** @return list<array{key:string,name:string,description:string}> */
    public static function catalog(): array
    {
        return array_map(fn ($k, $v) => ['key' => $k, 'name' => $v[0], 'description' => $v[1]], array_keys(self::TEMPLATES), self::TEMPLATES);
    }

    /** @return array{front: array, back: array} */
    public static function make(string $key, CardGeometry $g): array
    {
        if (! isset(self::TEMPLATES[$key])) {
            throw new InvalidArgumentException("Template $key tidak dikenal.");
        }
        [$frontBg, $front, $backBg, $back] = self::$key($g);
        // nama panjang tidak boleh keluar safe zone: beri lebar maks sesuai perataan
        $fit = fn ($l) => ($l['id'] ?? null) === 'name' && empty($l['allowBleed'])
            ? $l + ['fitWidth' => round(($l['align'] ?? 'left') === 'center'
                ? $g->width() - 2 * CardGeometry::SAFE
                : $g->width() - CardGeometry::SAFE - $l['x'], 2)]
            : $l;
        $front = array_map($fit, $front);

        return [
            'front' => self::side($g, $frontBg, $front, 'front'),
            'back' => self::side($g, $backBg, $key === 'kosong' ? $back : array_merge($back, self::backCopy($g, $backBg)), 'back'),
        ];
    }

    /** Bangun JSON satu sisi (BSD §5) lengkap dengan elemen sistem terkunci. */
    public static function side(CardGeometry $g, string $background, array $layers, string $which): array
    {
        $system = $which === 'front' ? SystemLayers::front($g, $background) : SystemLayers::back($g, $background);
        $n = 0;
        $layers = array_map(function ($l) use (&$n) {
            return ['id' => $l['id'] ?? 'l'.(++$n)] + $l;
        }, $layers);

        return [
            'size' => $g->size(),
            'background' => ['type' => 'color', 'value' => $background],
            'layers' => array_merge($layers, $system),
            'zones' => $g->zones(),
        ];
    }

    private static function c(string $n): string
    {
        return Palette::hex($n);
    }

    private static function text(string $text, float $x, float $y, float $size, string $color, array $extra = []): array
    {
        // $extra menimpa default (operator + mempertahankan kunci kiri, jadi $extra di kiri)
        return $extra + ['type' => 'text', 'text' => $text, 'x' => $x, 'y' => $y, 'size' => $size, 'color' => $color,
            'font' => 'Syne', 'weight' => 700, 'align' => 'left'];
    }

    private static function label(string $text, float $x, float $y, string $color, array $extra = []): array
    {
        return self::text($text, $x, $y, 6.8, $color, $extra + ['font' => 'IBM Plex Mono', 'weight' => 500, 'uppercase' => true, 'tracking' => 0.16]);
    }

    private static function body(string $text, float $x, float $y, string $color, array $extra = []): array
    {
        return self::text($text, $x, $y, 7.2, $color, $extra + ['font' => 'IBM Plex Sans', 'weight' => 400]);
    }

    /** Ajakan di sisi belakang (bisa diubah pengguna; bukan elemen sistem). */
    private static function backCopy(CardGeometry $g, string $bg): array
    {
        $ink = Palette::inkOn($bg);
        if ($g->portrait()) {
            return [
                self::text('Tap atau scan', $g->width() / 2, $g->height() - 17, 9, $ink, ['align' => 'center', 'id' => 'cta']),
                self::body('untuk simpan kontak', $g->width() / 2, $g->height() - 12.5, $ink, ['align' => 'center', 'size' => 6.5, 'id' => 'cta-sub']),
            ];
        }

        return [
            self::text('Tap atau scan', 30.0, $g->height() / 2 - 3, 9, $ink, ['id' => 'cta']),
            self::body('untuk simpan kontak', 30.0, $g->height() / 2 + 1.5, $ink, ['size' => 6.5, 'id' => 'cta-sub']),
        ];
    }

    // ── Template ──────────────────────────────────────────────────────────────
    // Masing-masing mengembalikan [latar depan, layer depan, latar belakang, layer belakang].

    /** Kartu kosong: latar putih, tanpa teks/bentuk; sisi belakang juga tanpa teks ajakan. */
    private static function kosong(CardGeometry $g): array
    {
        return [self::c('bone-0'), [], self::c('bone-0'), []];
    }

    private static function onyx(CardGeometry $g): array
    {
        $W = $g->width();
        $H = $g->height();
        $p = $g->portrait();

        return [self::c('onyx-950'), [
            ['type' => 'rect', 'x' => -3, 'y' => -3, 'w' => 4.6, 'h' => $H + 6, 'fill' => self::c('champagne-500')],
            ['type' => 'rings', 'cx' => $W - 9, 'cy' => $p ? 22 : 12, 'from' => 6, 'to' => 30, 'step' => 4, 'color' => self::c('champagne-500'), 'opacity' => 0.25],
            self::text('{{name}}', 8, $H - ($p ? 24 : 17), $p ? 16 : 18, self::c('bone-50'), ['id' => 'name']),
            self::label('{{title}}', 8, $H - ($p ? 17 : 11), self::c('champagne-500'), ['id' => 'title']),
            self::label('{{company}}', 8, $H - ($p ? 12.5 : 6.8), self::c('ash-400'), ['id' => 'company']),
        ], self::c('champagne-300'), []];
    }

    private static function ivory(CardGeometry $g): array
    {
        $W = $g->width();
        $H = $g->height();
        $cy = $g->portrait() ? $H / 2 + 4 : $H / 2;

        return [self::c('bone-50'), [
            self::text('{{name}}', $W / 2, $cy, $g->portrait() ? 15 : 17, self::c('ink-950'), ['align' => 'center', 'id' => 'name']),
            ['type' => 'line', 'x1' => $W / 2 - 6, 'y1' => $cy + 3.6, 'x2' => $W / 2 + 6, 'y2' => $cy + 3.6, 'color' => self::c('bronze-700'), 'width' => 0.3],
            self::label('{{title}}', $W / 2, $cy + 8.6, self::c('bronze-700'), ['align' => 'center', 'id' => 'title']),
            self::label('{{company}}', $W / 2, $H - CardGeometry::SAFE - 2, self::c('umber-600'), ['align' => 'center', 'id' => 'company']),
        ], self::c('bone-0'), []];
    }

    private static function garis(CardGeometry $g): array
    {
        $W = $g->width();
        $H = $g->height();
        $top = $g->portrait() ? max($g->contentTop() + 12, 24) : 15;

        return [self::c('bone-0'), [
            self::text('{{name}}', 6, $top, 15, self::c('ink-950'), ['id' => 'name']),
            self::body('{{title}}', 6, $top + 5.6, self::c('umber-600'), ['id' => 'title']),
            ['type' => 'line', 'x1' => 6, 'y1' => $H - 17, 'x2' => $W - 6, 'y2' => $H - 17, 'color' => self::c('ink-950'), 'width' => 0.25],
            self::label('{{phone}}', 6, $H - 11.5, self::c('ink-950'), ['id' => 'phone', 'tracking' => 0.06]),
            self::label('{{email}}', 6, $H - 7, self::c('umber-600'), ['id' => 'email', 'tracking' => 0.06, 'uppercase' => false]),
        ], self::c('bone-0'), [
            ['type' => 'line', 'x1' => 6, 'y1' => $H - 2.5, 'x2' => $W - 6, 'y2' => $H - 2.5, 'color' => self::c('ink-950'), 'width' => 0.25],
        ]];
    }

    private static function kreatif(CardGeometry $g): array
    {
        $H = $g->height();
        $p = $g->portrait();

        return [self::c('onyx-950'), [
            self::label('{{title}}', 6, $p ? $g->contentTop() + 6 : 10, self::c('bone-50'), ['id' => 'title']),
            self::text('{{name}}', -2, $H - 3, $p ? 30 : 40, self::c('champagne-500'), ['weight' => 800, 'id' => 'name', 'allowBleed' => true]),
        ], self::c('champagne-500'), []];
    }

    private static function natural(CardGeometry $g): array
    {
        $W = $g->width();
        $H = $g->height();

        return [self::c('umber-600'), [
            ['type' => 'rings', 'cx' => $W / 2, 'cy' => $H / 2, 'from' => 8, 'to' => 26, 'step' => 6, 'color' => self::c('bone-100'), 'opacity' => 0.18],
            self::text('{{name}}', $W / 2, $H / 2 + 1.5, $g->portrait() ? 14 : 15, self::c('bone-50'), ['weight' => 600, 'align' => 'center', 'id' => 'name']),
            self::label('{{title}}', $W / 2, $H / 2 + 7, self::c('bone-100'), ['align' => 'center', 'id' => 'title']),
        ], self::c('bone-100'), []];
    }

    private static function metal(CardGeometry $g): array
    {
        $W = $g->width();
        $H = $g->height();

        return [self::c('onyx-800'), [
            ['type' => 'rings', 'cx' => $W / 2, 'cy' => $H / 2, 'from' => 10, 'to' => 40, 'step' => 5, 'color' => self::c('ash-400'), 'opacity' => 0.3],
            self::text('{{name}}', $W / 2, $H / 2 + 1.5, $g->portrait() ? 15 : 16, self::c('bone-100'), ['align' => 'center', 'id' => 'name']),
            self::label('{{title}}', $W / 2, $H / 2 + 7.5, self::c('ash-400'), ['align' => 'center', 'id' => 'title']),
        ], self::c('onyx-900'), []];
    }

    private static function sage(CardGeometry $g): array
    {
        $H = $g->height();
        $p = $g->portrait();
        $top = $p ? $g->contentTop() + 4 : 9;

        return [self::c('sage-700'), [
            ['type' => 'line', 'x1' => 6, 'y1' => $top, 'x2' => 16, 'y2' => $top, 'color' => self::c('bone-50'), 'width' => 0.5],
            self::text('{{name}}', 6, $H - ($p ? 20 : 15), $p ? 15 : 16, self::c('bone-50'), ['id' => 'name']),
            self::label('{{title}}', 6, $H - ($p ? 14 : 9.5), self::c('bone-100'), ['id' => 'title']),
        ], self::c('bone-50'), []];
    }

    private static function korporat(CardGeometry $g): array
    {
        $W = $g->width();
        $H = $g->height();
        $p = $g->portrait();
        $band = $p ? max($g->contentTop() + 10, 24) : 16;
        $brand = self::c('sky-700'); // diganti brand.primary perusahaan di v1.5 (DBD §3.6)

        return [self::c('bone-0'), [
            ['type' => 'rect', 'x' => -3, 'y' => -3, 'w' => $W + 6, 'h' => $band + 3, 'fill' => $brand, 'id' => 'band'],
            self::text('{{company}}', 6, $band - 4.5, 9, Palette::inkOn($brand), ['id' => 'company']),
            self::text('{{name}}', 6, $band + ($p ? 14 : 11), 14, self::c('ink-950'), ['id' => 'name']),
            self::body('{{title}}', 6, $band + ($p ? 19.5 : 16), self::c('umber-600'), ['id' => 'title']),
            self::label('{{phone}}', 6, $H - 7, self::c('ink-950'), ['id' => 'phone', 'tracking' => 0.06]),
        ], self::c('bone-0'), [
            ['type' => 'rect', 'x' => -3, 'y' => $H - 1.5, 'w' => $W + 6, 'h' => 4.5, 'fill' => $brand, 'id' => 'band-back'],
        ]];
    }
}
