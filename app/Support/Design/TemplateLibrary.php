<?php

namespace App\Support\Design;

use InvalidArgumentException;

/**
 * 8 template klasik (DBD §8.4 + Garis, Sage) + koleksi desain. Semua warna dari palet tertutup, bidang solid saja.
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
        // koleksi desain — komposisi berbeda-beda; yang memakai logo organisasi ada di LOGO_TEMPLATES
        'diagonal' => ['Diagonal', 'Pita mustard miring melintasi kartu navy.'],
        'bintang' => ['Bintang', 'Bintang besar & logo bulat — partai, relawan, kampanye.'],
        'lencana' => ['Lencana', 'Logo dalam cincin ganda — komunitas & organisasi.'],
        'kop' => ['Kop', 'Logo perusahaan di atas garis, kontak rata kanan — gaya kop surat.'],
        'pilar' => ['Pilar', 'Empat bilah warna berjenjang di sisi kartu.'],
        'bukit' => ['Bukit', 'Lapisan bukit hijau dan matahari kecil.'],
        'fajar' => ['Fajar', 'Matahari terbit di cakrawala, nama di tengah.'],
        'heksa' => ['Heksa', 'Sarang lebah garis tipis — teknologi & startup.'],
        'konfeti' => ['Konfeti', 'Serpihan bentuk warna-warni di tepi kartu — kreatif & acara.'],
        'mondrian' => ['Mondrian', 'Blok warna primer dipisah garis tebal.'],
        'resmi' => ['Resmi', 'Bingkai ganda & logo di tengah — instansi, yayasan, partai.'],
        'selempang' => ['Selempang', 'Pita diagonal di sudut, teks rata kanan.'],
        'pinstripe' => ['Pinstripe', 'Garis halus champagne di atas hitam — butik & profesional.'],
        'logoblok' => ['Blok Logo', 'Separuh kartu warna perusahaan dengan logo besar.'],
        'titik' => ['Titik', 'Grid titik biru dan garis aksen koral.'],
        'retro' => ['Retro', 'Tiga pita hangat & lingkaran bertumpuk ala 70-an.'],
        'orbit' => ['Orbit', 'Lintasan planet tipis — konsultan, sains, agensi.'],
        'segitiga' => ['Segitiga', 'Komposisi segitiga mustard & gading di atas teal.'],
        'klinik' => ['Klinik', 'Ikon stetoskop & lingkaran lembut — dokter, klinik, apotek.'],
        'akademi' => ['Akademi', 'Ikon toga & bilah emas — guru, kursus, kampus.'],
        'toko' => ['Toko', 'Kanopi bergaris — UMKM, warung, toko.'],
        'kafe' => ['Kafe', 'Ikon kopi & bekas cangkir — kafe dan kedai.'],
        'kontak' => ['Kontak', 'Telepon, email, dan web berderet dengan ikon — praktis untuk sales.'],
        'noktah' => ['Noktah', 'Satu titik koral besar di samping nama — minimalis.'],
        'belah' => ['Belah', 'Kartu terbelah diagonal gading & hitam.'],
        'lapis' => ['Lapis', 'Kertas berlapis dengan bayangan solid.'],
        'mewah' => ['Mewah', 'Bingkai champagne & berlian kecil di atas hitam.'],
        'panji' => ['Panji', 'Umbul-umbul berekor berisi logo — partai, ormas, komunitas.'],
        'anggota' => ['Anggota', 'Kartu anggota: logo, nama organisasi, label ANGGOTA.'],
        'neon' => ['Neon', 'Dua garis tepi koral & biru yang bergeser — event & hiburan.'],
        'sudut' => ['Sudut', 'Dua siku tipis mengapit nama di tengah.'],
        'segel' => ['Segel', 'Cap bergerigi dengan logo di tengah — organisasi resmi.'],
        'kode' => ['Kode', 'Tipografi monospace ala terminal — developer.'],
        'majalah' => ['Majalah', 'Gaya sampul editorial: garis tebal, nama besar.'],
        'arsir' => ['Arsir', 'Arsiran diagonal di satu sisi kartu sage.'],
    ];

    /** Desain dengan slot logo organisasi (`kit:logo`) — galeri memberi tanda "Logo". */
    public const LOGO_TEMPLATES = ['bintang', 'lencana', 'kop', 'resmi', 'logoblok', 'panji', 'anggota', 'segel'];

    /** @return list<array{key:string,name:string,description:string,logo:bool}> */
    public static function catalog(): array
    {
        return array_map(fn ($k, $v) => ['key' => $k, 'name' => $v[0], 'description' => $v[1], 'logo' => in_array($k, self::LOGO_TEMPLATES, true)], array_keys(self::TEMPLATES), self::TEMPLATES);
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
            ? $l + ['fitWidth' => round(match ($l['align'] ?? 'left') {
                'center' => $g->width() - 2 * CardGeometry::SAFE,
                'right' => $l['x'] - CardGeometry::SAFE,
                default => $g->width() - CardGeometry::SAFE - $l['x'],
            }, 2)]
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

    // ── Koleksi desain ────────────────────────────────────────────────────────────
    // Tiap desain punya komposisi sendiri (bentuk, tipografi, tata letak) — bukan sekadar ganti warna. Semua vektor
    // (ringan dimuat), bentuk dekoratif menjauhi lubang lanyard, teks tetap di safe zone. Desain bertanda `kit:logo`
    // otomatis memakai logo organisasi (perusahaan / komunitas / partai) dari brand kit workspace.

    private static function rect(float $x, float $y, float $w, float $h, string $fill, array $extra = []): array
    {
        return $extra + ['type' => 'rect', 'x' => round($x, 2), 'y' => round($y, 2), 'w' => round($w, 2), 'h' => round($h, 2), 'fill' => self::c($fill)];
    }

    private static function ell(float $x, float $y, float $w, float $h, string $fill, array $extra = []): array
    {
        return $extra + ['type' => 'ellipse', 'x' => round($x, 2), 'y' => round($y, 2), 'w' => round($w, 2), 'h' => round($h, 2), 'fill' => self::c($fill)];
    }

    private static function poly(float $x, float $y, float $w, float $h, int $sides, string $fill, array $extra = []): array
    {
        return $extra + ['type' => 'polygon', 'x' => round($x, 2), 'y' => round($y, 2), 'w' => round($w, 2), 'h' => round($h, 2), 'sides' => $sides, 'fill' => self::c($fill)];
    }

    private static function ln(float $x1, float $y1, float $x2, float $y2, string $color, float $width = 0.3, array $extra = []): array
    {
        return $extra + ['type' => 'line', 'x1' => round($x1, 2), 'y1' => round($y1, 2), 'x2' => round($x2, 2), 'y2' => round($y2, 2), 'color' => self::c($color), 'width' => $width];
    }

    private static function ring(float $cx, float $cy, float $r, string $color, float $width = 0.3, array $extra = []): array
    {
        return $extra + ['type' => 'circle', 'cx' => round($cx, 2), 'cy' => round($cy, 2), 'r' => round($r, 2), 'stroke' => self::c($color), 'strokeWidth' => $width];
    }

    private static function glyph(string $name, float $x, float $y, float $size, string $color, array $extra = []): array
    {
        return $extra + ['type' => 'glyph', 'name' => $name, 'x' => round($x, 2), 'y' => round($y, 2), 'size' => $size, 'color' => self::c($color), 'strokeWidth' => 1.5];
    }

    /** Slot logo organisasi (brand kit). */
    private static function logo(float $x, float $y, float $w, float $h, array $extra = []): array
    {
        return $extra + ['id' => 'logo', 'name' => 'Logo organisasi', 'type' => 'image', 'asset' => 'kit:logo', 'x' => round($x, 2), 'y' => round($y, 2), 'w' => round($w, 2), 'h' => round($h, 2), 'fit' => 'contain'];
    }

    /** Pita tipis selebar kartu di tepi bawah sisi belakang. */
    private static function backBand(CardGeometry $g, string $color, float $h = 1.5): array
    {
        return [self::rect(-3, $g->height() - $h, $g->width() + 6, $h + 3, $color)];
    }

    /** Teks nama / jabatan / usaha bertumpuk. */
    private static function stack(float $x, float $base, string $ink, string $accent, string $sub, float $size, string $align = 'left', ?float $maxW = null): array
    {
        $fit = $maxW ? ['fitWidth' => round($maxW, 2)] : [];

        return [
            self::text('{{name}}', $x, round($base, 2), $size, self::c($ink), ['id' => 'name', 'align' => $align] + $fit),
            self::label('{{title}}', $x, round($base + 5.6, 2), self::c($accent), ['id' => 'title', 'align' => $align] + $fit),
            self::label('{{company}}', $x, round($base + 9.8, 2), self::c($sub), ['id' => 'company', 'align' => $align] + $fit),
        ];
    }

    /** Diagonal: pita mustard miring melintasi bawah kartu navy. */
    private static function diagonal(CardGeometry $g): array
    {
        [$W, $H, $p, $T] = [$g->width(), $g->height(), $g->portrait(), $g->contentTop()];
        $y = $H * ($p ? 0.66 : 0.64);

        return [self::c('navy-800'), [
            self::rect(-10, $y, $W + 20, $H, 'mustard-400', ['rotate' => -8, 'id' => 'band']),
            self::rect(-10, $y - 2.6, $W + 20, 0.7, 'bone-50', ['rotate' => -8]),
            ...self::stack(6, $T + ($p ? 16 : 9), 'bone-50', 'mustard-400', 'bone-200', $p ? 14 : 15, maxW: $p ? $W - 12 : 50),
        ], self::c('navy-800'), self::backBand($g, 'mustard-400', 2.5)];
    }

    /** Bintang: kartu partai / relawan — bintang besar, logo bulat. */
    private static function bintang(CardGeometry $g): array
    {
        [$W, $H, $p, $T] = [$g->width(), $g->height(), $g->portrait(), $g->contentTop()];
        $s = $p ? 12.0 : 14.0;

        return [self::c('coral-700'), [
            $p ? self::poly($W - 38, $H - 36, 50, 50, 5, 'mustard-400', ['star' => true, 'inner' => 0.45, 'rotate' => 12])
               : self::poly($W - 40, $H - 38, 52, 52, 5, 'mustard-400', ['star' => true, 'inner' => 0.45, 'rotate' => 12]),
            self::ring(6 + $s / 2, $T + 1 + $s / 2, $s / 2 + 0.9, 'mustard-400', 0.4),
            self::logo(6, $T + 1, $s, $s, ['mask' => 'circle']),
            ...self::stack(6, $p ? $T + $s + 13 : $H - 19, 'bone-50', 'sand-200', 'rose-200', $p ? 14 : 16, maxW: $p ? $W - 12 : 37),
        ], self::c('coral-700'), [
            self::poly(-6, -6, 16, 16, 5, 'mustard-400', ['star' => true, 'inner' => 0.45]),
        ]];
    }

    /** Lencana: logo dalam cincin ganda — komunitas & organisasi. */
    private static function lencana(CardGeometry $g): array
    {
        [$W, $H, $p, $T] = [$g->width(), $g->height(), $g->portrait(), $g->contentTop()];
        [$cx, $cy] = $p ? [$W / 2, $T + 17] : [18.0, $H / 2];
        $badge = [
            self::ring($cx, $cy, 13.5, 'champagne-300', 0.25),
            self::ring($cx, $cy, 12, 'champagne-300', 0.6),
            self::logo($cx - 8, $cy - 8, 16, 16, ['mask' => 'circle']),
        ];
        $text = $p
            ? [
                self::label('{{company}}', $W / 2, $cy + 21, self::c('champagne-300'), ['id' => 'company', 'align' => 'center', 'fitWidth' => $W - 12]),
                self::text('{{name}}', $W / 2, $cy + 29, 14, self::c('bone-50'), ['id' => 'name', 'align' => 'center']),
                self::label('{{title}}', $W / 2, $cy + 34.6, self::c('sage-300'), ['id' => 'title', 'align' => 'center', 'fitWidth' => $W - 12]),
            ]
            : [
                self::label('{{company}}', 37, $cy - 7, self::c('champagne-300'), ['id' => 'company', 'fitWidth' => $W - 41]),
                self::text('{{name}}', 37, $cy + 1.5, 15, self::c('bone-50'), ['id' => 'name']),
                self::label('{{title}}', 37, $cy + 7, self::c('sage-300'), ['id' => 'title', 'fitWidth' => $W - 41]),
            ];

        return [self::c('forest-800'), [...$badge, ...$text], self::c('forest-800'), [
            ['type' => 'rings', 'cx' => 0, 'cy' => 0, 'from' => 6, 'to' => 18, 'step' => 3, 'color' => self::c('champagne-300'), 'opacity' => 0.3],
        ]];
    }

    /** Kop: logo perusahaan di atas garis, kontak rata kanan — gaya kop surat. */
    private static function kop(CardGeometry $g): array
    {
        [$W, $H, $p, $T] = [$g->width(), $g->height(), $g->portrait(), $g->contentTop()];
        $rule = $T + 14;
        $contact = $p
            ? [
                self::label('{{phone}}', 6, $H - 15, self::c('ink-950'), ['id' => 'phone', 'tracking' => 0.04]),
                self::label('{{email}}', 6, $H - 10.5, self::c('umber-600'), ['id' => 'email', 'tracking' => 0.04, 'uppercase' => false]),
            ]
            : [
                self::label('{{phone}}', $W - 6, $H - 13.4, self::c('ink-950'), ['id' => 'phone', 'tracking' => 0.04, 'align' => 'right']),
                self::label('{{email}}', $W - 6, $H - 9.2, self::c('umber-600'), ['id' => 'email', 'tracking' => 0.04, 'uppercase' => false, 'align' => 'right']),
            ];

        return [self::c('bone-0'), [
            self::logo(6, $T + 1, $p ? 24 : 26, 10, ['fit' => 'contain']),
            self::ln(6, $rule, $W - 6, $rule, 'sky-700', 0.4),
            self::text('{{name}}', 6, $p ? $rule + 14 : $H - 19, $p ? 13 : 14, self::c('ink-950'), ['id' => 'name']),
            self::body('{{title}}', 6, $p ? $rule + 19.5 : $H - 13.4, self::c('sky-700'), ['id' => 'title', 'fitWidth' => $p ? $W - 12 : 36]),
            self::label('{{company}}', 6, $p ? $rule + 24 : $H - 9.2, self::c('umber-600'), ['id' => 'company', 'fitWidth' => $p ? $W - 12 : 36]),
            ...$contact,
        ], self::c('bone-0'), self::backBand($g, 'sky-700', 2.5)];
    }

    /** Pilar: empat bilah berjenjang (plum → pasir) di sisi kartu. */
    private static function pilar(CardGeometry $g): array
    {
        [$W, $H, $p] = [$g->width(), $g->height(), $g->portrait()];
        $bars = [['plum-800', 0], ['terracotta-600', 1], ['mustard-400', 2], ['sand-200', 3]];
        $shapes = [];
        if ($p) {
            foreach ([[$H - 9, 12], [$H - 15, 6], [$H - 20, 5], [$H - 24, 4]] as $i => [$y, $h]) {
                $shapes[] = self::rect(-3, $y, $W + 6, $h, $bars[$i][0]);
            }
        } else {
            foreach ([[-3, 9], [6, 5], [11, 4], [15, 3]] as $i => [$x, $w]) {
                $shapes[] = self::rect($x, -3, $w, $H + 6, $bars[$i][0]);
            }
        }

        return [self::c('bone-50'), [
            ...$shapes,
            ...($p ? self::stack(6, $H - 42, 'ink-950', 'terracotta-600', 'umber-600', 14)
                   : self::stack(24, $H - 19, 'ink-950', 'terracotta-600', 'umber-600', 15)),
        ], self::c('plum-800'), self::backBand($g, 'mustard-400')];
    }

    /** Bukit: lapisan bukit hijau & matahari kecil. */
    private static function bukit(CardGeometry $g): array
    {
        [$W, $H, $p, $T] = [$g->width(), $g->height(), $g->portrait(), $g->contentTop()];
        $hills = $p
            ? [self::ell(-20, $H - 30, 62, 52, 'sage-300'), self::ell(12, $H - 23, 70, 52, 'sage-700'), self::ell(-25, $H - 13, 62, 40, 'forest-800')]
            : [self::ell(-15, $H - 20, 70, 40, 'sage-300'), self::ell(25, $H - 16, 80, 40, 'sage-700'), self::ell(-20, $H - 9, 60, 30, 'forest-800')];

        return [self::c('bone-50'), [
            $p ? self::ell($W - 18, $H - 44, 10, 10, 'mustard-400') : self::ell($W - 26, 15, 10, 10, 'mustard-400'),
            ...$hills,
            ...self::stack(6, $T + ($p ? 16 : 10), 'forest-800', 'sage-700', 'umber-600', $p ? 14 : 15, maxW: $p ? null : 52),
        ], self::c('forest-800'), [self::ell($W - 30, $H - 8, 50, 30, 'sage-700')]];
    }

    /** Fajar: matahari terbit di garis cakrawala, teks di tengah atas. */
    private static function fajar(CardGeometry $g): array
    {
        [$W, $H, $p, $T] = [$g->width(), $g->height(), $g->portrait(), $g->contentTop()];
        $r = $p ? 22.0 : 20.0;

        return [self::c('sand-200'), [
            ['type' => 'rings', 'cx' => $W / 2, 'cy' => $H + 2, 'from' => $r + 4, 'to' => $r + 24, 'step' => 5, 'color' => self::c('terracotta-600'), 'opacity' => 0.35],
            self::ell($W / 2 - $r, $H + 2 - $r, 2 * $r, 2 * $r, 'terracotta-600', ['id' => 'sun']),
            self::ln(-3, $H - 5, $W + 3, $H - 5, 'umber-600', 0.4),
            ...self::stack($W / 2, $T + ($p ? 22 : 12), 'ink-950', 'amber-700', 'umber-600', $p ? 14 : 16, 'center'),
        ], self::c('sand-200'), self::backBand($g, 'terracotta-600', 2.5)];
    }

    /** Heksa: sarang lebah garis tipis — teknologi & startup. */
    private static function heksa(CardGeometry $g): array
    {
        [$W, $H, $p, $T] = [$g->width(), $g->height(), $g->portrait(), $g->contentTop()];
        $cells = $p
            ? [[6, 60], [6, 74], [18, 53], [18, 67, 1], [18, 81], [30, 60], [30, 74], [42, 53], [42, 67]]
            : [[52, 16], [52, 30], [64, 9], [64, 23, 1], [64, 37], [76, 16], [76, 30], [76, 44]];
        $hex = array_map(fn ($c) => isset($c[2])
            ? self::poly($c[0], $c[1], 14, 14, 6, 'sky-700')
            : self::poly($c[0], $c[1], 14, 14, 6, 'sky-300', ['fill' => 'none', 'stroke' => self::c('sky-300'), 'strokeWidth' => 0.3, 'opacity' => 0.7]), $cells);

        return [self::c('onyx-900'), [
            ...$hex,
            ...($p ? self::stack(6, $T + 16, 'bone-50', 'sky-300', 'ash-400', 14)
                   : self::stack(6, $H - 19, 'bone-50', 'sky-300', 'ash-400', 15, maxW: 42)),
        ], self::c('onyx-900'), [
            self::poly(-6, -6, 18, 18, 6, 'sky-300', ['fill' => 'none', 'stroke' => self::c('sky-300'), 'strokeWidth' => 0.3, 'opacity' => 0.7]),
            self::poly(10, 2, 8, 8, 6, 'sky-700'),
        ]];
    }

    /** Konfeti: serpihan bentuk warna-warni di tepi kartu — kreatif & acara. */
    private static function konfeti(CardGeometry $g): array
    {
        [$W, $H, $p] = [$g->width(), $g->height(), $g->portrait()];
        // [fx, fy, bentuk, warna, ukuran, rotasi] — posisi relatif agar tetap di tepi pada dua orientasi
        $bits = [
            [0.09, 0.14, 'dot', 'coral-300', 5, 0], [0.25, 0.1, 'tri', 'sky-300', 6, 15], [0.72, 0.13, 'sq', 'mustard-400', 5, 30],
            [0.06, 0.5, 'bar', 'sage-700', 6, 60], [0.1, 0.84, 'tri', 'mustard-400', 7, -20], [0.32, 0.88, 'bar', 'sky-700', 7, 20],
            [0.66, 0.89, 'bar', 'coral-700', 8, -25], [0.86, 0.82, 'dot', 'sage-300', 6, 0], [0.92, 0.5, 'dot', 'coral-700', 3, 0],
        ];
        $shapes = array_map(function ($b) use ($W, $H) {
            [$fx, $fy, $kind, $color, $s, $rot] = $b;
            $x = $fx * $W - $s / 2;
            $y = $fy * $H - $s / 2;
            $extra = $rot ? ['rotate' => $rot] : [];

            return match ($kind) {
                'dot' => self::ell($x, $y, $s, $s, $color),
                'tri' => self::poly($x, $y, $s, $s, 3, $color, $extra),
                'sq' => self::rect($x, $y, $s, $s, $color, $extra),
                'bar' => self::rect($x, $y + $s / 2 - 0.9, $s, 1.8, $color, $extra + ['rx' => 0.9]),
            };
        }, $bits);
        $back = $p
            ? [[0.12, 0.05, 'coral-300'], [0.88, 0.06, 'mustard-400'], [0.1, 0.42, 'sky-300'], [0.9, 0.46, 'sage-300'], [0.12, 0.93, 'coral-700'], [0.88, 0.92, 'sky-700']]
            : [[0.07, 0.1, 'coral-300'], [0.2, 0.12, 'mustard-400'], [0.8, 0.1, 'sky-300'], [0.72, 0.9, 'sage-300'], [0.88, 0.88, 'coral-700']];

        return [self::c('bone-0'), [
            ...$shapes,
            self::text('{{name}}', $W / 2, $H / 2 + 1.5, $p ? 14 : 16, self::c('ink-950'), ['id' => 'name', 'align' => 'center', 'fitWidth' => $W - 20]),
            self::label('{{title}}', $W / 2, $H / 2 + 7.5, self::c('coral-700'), ['id' => 'title', 'align' => 'center', 'fitWidth' => $W - 20]),
        ], self::c('bone-0'), array_map(fn ($b) => self::ell($b[0] * $W - 2, $b[1] * $H - 2, 4, 4, $b[2]), $back)];
    }

    /** Mondrian: blok warna primer dipisah garis tebal. */
    private static function mondrian(CardGeometry $g): array
    {
        [$W, $H, $p] = [$g->width(), $g->height(), $g->portrait()];
        $k = fn ($x1, $y1, $x2, $y2) => self::ln($x1, $y1, $x2, $y2, 'ink-950', 1.2, ['name' => 'Garis']);
        if ($p) {
            return [self::c('bone-0'), [
                self::rect(-3, -3, 20.5, 33, 'coral-700'), self::rect(36.5, -3, 20.5, 33, 'mustard-400'), self::rect(36.5, 70, 20.5, 18.6, 'sky-700'),
                $k(17.5, -3, 17.5, 30), $k(36.5, -3, 36.5, 30), $k(-3, 30, $W + 3, 30), $k(36.5, 70, 36.5, $H + 3), $k(36.5, 70, $W + 3, 70),
                ...self::stack(6, 44, 'ink-950', 'coral-700', 'umber-600', 14),
                self::label('{{phone}}', 6, $H - 12, self::c('ink-950'), ['id' => 'phone', 'tracking' => 0.04, 'fitWidth' => 28]),
            ], self::c('bone-0'), self::backBand($g, 'coral-700', 2.5)];
        }

        return [self::c('bone-0'), [
            self::rect(-3, -3, 31, 23, 'coral-700'), self::rect(-3, 44, 31, 13, 'mustard-400'), self::rect(66, 34, $W - 63, 23, 'sky-700'),
            $k(28, -3, 28, $H + 3), $k(-3, 20, 28, 20), $k(-3, 44, 28, 44), $k(28, 34, $W + 3, 34), $k(66, 34, 66, $H + 3),
            ...self::stack(32, 20, 'ink-950', 'coral-700', 'umber-600', 14, maxW: $W - 36),
            self::label('{{phone}}', 32, $H - 9, self::c('ink-950'), ['id' => 'phone', 'tracking' => 0.04, 'fitWidth' => 31]),
        ], self::c('bone-0'), self::backBand($g, 'coral-700', 2.5)];
    }

    /** Resmi: bingkai ganda & logo di tengah — instansi, yayasan, partai. */
    private static function resmi(CardGeometry $g): array
    {
        [$W, $H, $p, $T] = [$g->width(), $g->height(), $g->portrait(), $g->contentTop()];
        $ly = max($T + 2, $p ? 12 : 9);
        [$lw, $lh] = $p ? [18, 14] : [16, 12];
        $ny = $ly + $lh + ($p ? 13 : 9);

        return [self::c('bone-50'), [
            ...self::frame($g, 2.5, 'bronze-700', 0.4), ...self::frame($g, 3.4, 'bronze-700', 0.15),
            self::logo($W / 2 - $lw / 2, $ly, $lw, $lh),
            self::text('{{name}}', $W / 2, $ny, $p ? 13 : 14, self::c('ink-950'), ['id' => 'name', 'align' => 'center']),
            self::label('{{title}}', $W / 2, $ny + 5.6, self::c('bronze-700'), ['id' => 'title', 'align' => 'center', 'fitWidth' => $W - 14]),
            self::label('{{company}}', $W / 2, $H - CardGeometry::SAFE - 2.5, self::c('umber-600'), ['id' => 'company', 'align' => 'center', 'fitWidth' => $W - 14]),
        ], self::c('bone-50'), [...self::frame($g, 2.5, 'bronze-700', 0.4)]];
    }

    /** Bingkai garis sejauh $i dari tepi potong; garis atas diputus di lubang lanyard. */
    private static function frame(CardGeometry $g, float $i, string $color, float $width): array
    {
        [$W, $H] = [$g->width(), $g->height()];
        $line = fn ($x1, $y1, $x2, $y2) => self::ln($x1, $y1, $x2, $y2, $color, $width);
        $slot = $g->zones()['slot'] ?? null;
        $top = $slot
            ? [$line($i, $i, $slot['x'] - 1, $i), $line($slot['x'] + $slot['w'] + 1, $i, $W - $i, $i)]
            : [$line($i, $i, $W - $i, $i)];

        return [...$top, $line($W - $i, $i, $W - $i, $H - $i), $line($W - $i, $H - $i, $i, $H - $i), $line($i, $H - $i, $i, $i)];
    }

    /** Selempang: pita diagonal di sudut kiri atas, teks rata kanan. */
    private static function selempang(CardGeometry $g): array
    {
        [$W, $H, $p] = [$g->width(), $g->height(), $g->portrait()];
        $c = $p ? 1.5 : 4.0; // pusat pita (mm dari sudut)
        $sash = [
            self::rect($c - 20, $c - 3, 40, 6, 'rose-200', ['rotate' => -45]),
            self::rect($c + 2.8 - 20, $c + 2.8 - 0.4, 40, 0.8, 'mustard-400', ['rotate' => -45]),
        ];

        return [self::c('plum-800'), [
            ...$sash,
            ...self::stack($W - 6, $H - ($p ? 24 : 19), 'bone-50', 'rose-200', 'mustard-400', $p ? 14 : 16, 'right'),
        ], self::c('plum-800'), $sash];
    }

    /** Pinstripe: garis-garis halus champagne di atas hitam — jas & butik. */
    private static function pinstripe(CardGeometry $g): array
    {
        [$W, $H, $p] = [$g->width(), $g->height(), $g->portrait()];
        $lines = [];
        for ($i = 0; $i < 11; $i++) {
            $o = round(0.85 - $i * 0.06, 2);
            $lines[] = $p
                ? self::ln(-3, $H - 2 - $i * 2.5, $W + 3, $H - 2 - $i * 2.5, 'champagne-500', 0.2, ['opacity' => $o])
                : self::ln(2 + $i * 2.5, -3, 2 + $i * 2.5, $H + 3, 'champagne-500', 0.2, ['opacity' => $o]);
        }
        $lines[] = $p ? self::rect(-3, $H - 30, $W + 6, 1.2, 'champagne-500') : self::rect(29, -3, 1.2, $H + 6, 'champagne-500');

        return [self::c('onyx-950'), [
            ...$lines,
            ...($p ? self::stack(6, $H - 46, 'bone-50', 'champagne-500', 'ash-400', 14)
                   : self::stack(36, $H - 19, 'bone-50', 'champagne-500', 'ash-400', 15, maxW: $W - 40)),
        ], self::c('onyx-950'), [
            self::ln(-3, $g->height() - 0.8, $W + 3, $g->height() - 0.8, 'champagne-500', 0.2),
            self::ln(-3, $g->height() - 2.2, $W + 3, $g->height() - 2.2, 'champagne-500', 0.2, ['opacity' => 0.6]),
        ]];
    }

    /** Blok logo: separuh kartu warna perusahaan dengan logo, separuh lagi identitas. */
    private static function logoblok(CardGeometry $g): array
    {
        [$W, $H, $p, $T] = [$g->width(), $g->height(), $g->portrait(), $g->contentTop()];
        if ($p) {
            return [self::c('bone-0'), [
                self::rect(-3, -3, $W + 6, 36, 'sky-700', ['id' => 'block']),
                self::logo($W / 2 - 12, $T + 3, 24, 14),
                self::label('{{company}}', 6, 44, self::c('sky-700'), ['id' => 'company', 'fitWidth' => $W - 12]),
                self::text('{{name}}', 6, 52, 14, self::c('ink-950'), ['id' => 'name']),
                self::body('{{title}}', 6, 57.6, self::c('umber-600'), ['id' => 'title', 'fitWidth' => $W - 12]),
                self::label('{{phone}}', 6, $H - 10, self::c('ink-950'), ['id' => 'phone', 'tracking' => 0.04]),
            ], self::c('bone-0'), self::backBand($g, 'sky-700', 2.5)];
        }

        return [self::c('bone-0'), [
            self::rect(-3, -3, 37, $H + 6, 'sky-700', ['id' => 'block']),
            self::logo(6, $H / 2 - 9, 23, 18),
            self::label('{{company}}', 40, $H / 2 - 8, self::c('sky-700'), ['id' => 'company', 'fitWidth' => $W - 44]),
            self::text('{{name}}', 40, $H / 2 + 1, 14, self::c('ink-950'), ['id' => 'name']),
            self::body('{{title}}', 40, $H / 2 + 6.6, self::c('umber-600'), ['id' => 'title', 'fitWidth' => $W - 44]),
            self::label('{{phone}}', 40, $H - 8, self::c('ink-950'), ['id' => 'phone', 'tracking' => 0.04, 'fitWidth' => $W - 44]),
        ], self::c('bone-0'), self::backBand($g, 'sky-700', 2.5)];
    }

    /** Titik: grid titik biru & garis aksen koral. */
    private static function titik(CardGeometry $g): array
    {
        [$W, $H, $p, $T] = [$g->width(), $g->height(), $g->portrait(), $g->contentTop()];
        $dots = [];
        [$x0, $y0, $cols, $rows] = $p ? [26, max($T + 2, 14), 6, 5] : [56, 30, 6, 5];
        for ($i = 0; $i < $cols; $i++) {
            for ($j = 0; $j < $rows; $j++) {
                $dots[] = self::ell($x0 + $i * 4.5, $y0 + $j * 4.5, 1.1, 1.1, 'sky-700', ['opacity' => round(1 - ($i + $j) * 0.07, 2)]);
            }
        }
        $back = [];
        for ($i = 0; $i < 5; $i++) {
            for ($j = 0; $j < 3; $j++) {
                $back[] = self::ell(4 + $i * 3, 4 + $j * 3, 0.9, 0.9, 'sky-700');
            }
        }

        return [self::c('bone-0'), [
            ...$dots,
            self::rect(6, $p ? $H - 39 : $T + 2, 8, 1.6, 'coral-700', ['id' => 'accent']),
            ...self::stack(6, $H - ($p ? 24 : 19), 'ink-950', 'sky-700', 'umber-600', $p ? 14 : 15, maxW: $p ? null : 48),
        ], self::c('bone-0'), $back];
    }

    /** Retro: tiga pita hangat & lingkaran bertumpuk ala 70-an. */
    private static function retro(CardGeometry $g): array
    {
        [$W, $H, $p, $T] = [$g->width(), $g->height(), $g->portrait(), $g->contentTop()];
        [$cx, $cy] = $p ? [$W / 2, $T + 14] : [$W - 20, 20.0];
        $sun = array_map(fn ($s) => self::ell($cx - $s[0], $cy - $s[0], 2 * $s[0], 2 * $s[0], $s[1]), [[10, 'mustard-400'], [7, 'amber-700'], [4, 'terracotta-600']]);

        return [self::c('sand-200'), [
            ...$sun,
            self::rect(-3, $H - 14.5, $W + 6, 3, 'mustard-400'),
            self::rect(-3, $H - 10.5, $W + 6, 3.5, 'amber-700'),
            self::rect(-3, $H - 6, $W + 6, 9, 'terracotta-600'),
            ...($p ? self::stack($W / 2, $cy + 24, 'ink-950', 'terracotta-600', 'umber-600', 14, 'center')
                   : self::stack(6, $H - 27, 'ink-950', 'terracotta-600', 'umber-600', 15, maxW: 50)),
        ], self::c('sand-200'), [
            self::rect(-3, $H - 3.3, $W + 6, 1.2, 'mustard-400'),
            self::rect(-3, $H - 1.5, $W + 6, 4.5, 'terracotta-600'),
        ]];
    }

    /** Orbit: lintasan planet tipis — konsultan, sains, agensi. */
    private static function orbit(CardGeometry $g): array
    {
        [$W, $H, $p, $T] = [$g->width(), $g->height(), $g->portrait(), $g->contentTop()];
        [$cx, $cy] = $p ? [$W / 2, $H - 24] : [$W - 20, $H / 2 + 2];

        return [self::c('navy-800'), [
            self::ring($cx, $cy, 15, 'sky-300', 0.3),
            self::ring($cx, $cy, 9, 'sky-300', 0.2, ['opacity' => 0.6]),
            self::ell($cx - 4, $cy - 4, 8, 8, 'sky-700'),
            self::ell($cx + 11.5 - 2.2, $cy - 9.6 - 2.2, 4.4, 4.4, 'mustard-400', ['id' => 'planet']),
            self::ell($cx - 6.4 - 1, $cy + 6.4 - 1, 2, 2, 'sky-300'),
            ...($p ? self::stack(6, $T + 14, 'bone-50', 'mustard-400', 'sky-300', 14)
                   : self::stack(6, $H - 19, 'bone-50', 'mustard-400', 'sky-300', 15, maxW: 42)),
        ], self::c('navy-800'), [
            ['type' => 'rings', 'cx' => -2, 'cy' => -2, 'from' => 6, 'to' => 16, 'step' => 5, 'color' => self::c('sky-300'), 'opacity' => 0.35],
        ]];
    }

    /** Segitiga: komposisi segitiga mustard & gading di atas teal. */
    private static function segitiga(CardGeometry $g): array
    {
        [$W, $H, $p, $T] = [$g->width(), $g->height(), $g->portrait(), $g->contentTop()];
        $tri = $p
            ? [self::poly(-8, $H - 34, 40, 37, 3, 'mustard-400'), self::poly(16, $H - 24, 30, 27, 3, 'bone-0', ['opacity' => 0.9]),
                self::poly(26, $H - 42, 30, 30, 3, 'mustard-400', ['fill' => 'none', 'stroke' => self::c('mustard-400'), 'strokeWidth' => 0.4])]
            : [self::poly(-8, $H - 30, 40, 34, 3, 'mustard-400'), self::poly(18, $H - 20, 26, 23, 3, 'bone-0', ['opacity' => 0.9]),
                self::poly(30, $H - 34, 30, 28, 3, 'mustard-400', ['fill' => 'none', 'stroke' => self::c('mustard-400'), 'strokeWidth' => 0.4])];

        return [self::c('teal-700'), [
            ...$tri,
            ...($p ? self::stack(6, $T + 16, 'bone-0', 'mustard-400', 'bone-200', 14)
                   : self::stack($W - 6, max($T + 9, 18), 'bone-0', 'mustard-400', 'bone-200', 15, 'right')),
        ], self::c('teal-700'), [self::poly($W - 12, $H - 10, 14, 12, 3, 'mustard-400')]];
    }

    /** Klinik: ubin ikon stetoskop & lingkaran lembut — dokter, klinik, apotek. */
    private static function klinik(CardGeometry $g): array
    {
        [$W, $H, $p, $T] = [$g->width(), $g->height(), $g->portrait(), $g->contentTop()];

        return [self::c('bone-0'), [
            self::ell($W - 30, $H - ($p ? 36 : 30), 50, 50, 'sky-300', ['opacity' => 0.35]),
            self::rect(6, $T + 1, 12, 12, 'sky-700', ['rx' => 3]),
            self::glyph('stethoscope', 8.5, $T + 3.5, 7, 'bone-0'),
            ...self::stack(6, $H - ($p ? 30 : 19), 'ink-950', 'sky-700', 'umber-600', $p ? 14 : 15),
        ], self::c('bone-0'), self::backBand($g, 'sky-700', 2.5)];
    }

    /** Akademi: ikon toga & bilah emas — guru, kursus, kampus. */
    private static function akademi(CardGeometry $g): array
    {
        [$W, $H, $p, $T] = [$g->width(), $g->height(), $g->portrait(), $g->contentTop()];

        return [self::c('navy-800'), [
            $p ? self::rect(-3, $H - 10, $W + 6, 13, 'mustard-400') : self::rect($W - 10, 12, 13, $H - 9, 'mustard-400'),
            self::glyph('graduation-cap', 6, $T + 1, 10, 'mustard-400'),
            ...self::stack(6, $H - ($p ? 30 : 19), 'bone-50', 'mustard-400', 'bone-200', $p ? 14 : 15, maxW: $p ? null : $W - 19),
        ], self::c('navy-800'), self::backBand($g, 'mustard-400', 2.5)];
    }

    /** Toko: kanopi bergaris di atas — UMKM, warung, toko. */
    private static function toko(CardGeometry $g): array
    {
        [$W, $H, $p] = [$g->width(), $g->height(), $g->portrait()];
        $a = $p ? 10.0 : 9.0;
        $slot = $g->zones()['slot'] ?? null;
        $free = fn ($x, $w) => ! $slot || $x + $w <= $slot['x'] || $x >= $slot['x'] + $slot['w'];
        $shapes = [self::rect(-3, -3, $W + 6, $a + 3, 'terracotta-600', ['id' => 'awning'])];
        for ($x = 3.0, $i = 0; $x < $W + 3; $x += 6, $i++) {
            if ($i % 2 && $free($x, 6)) {
                $shapes[] = self::rect($x, -3, 6, $a + 3, 'bone-0');
            }
            if ($free($x, 6)) {
                $shapes[] = self::ell($x, $a - 2, 6, 4, $i % 2 ? 'bone-0' : 'terracotta-600');
            }
        }

        return [self::c('bone-50'), [
            ...$shapes,
            ...self::stack(6, $a + ($p ? 26 : 16), 'ink-950', 'terracotta-600', 'umber-600', $p ? 14 : 15),
            self::glyph('phone', 6, $H - 10.6, 3.2, 'terracotta-600'),
            self::label('{{phone}}', 11, $H - 8, self::c('ink-950'), ['id' => 'phone', 'tracking' => 0.04, 'fitWidth' => $W - 17]),
        ], self::c('terracotta-600'), []];
    }

    /** Kafe: ikon kopi & bekas cangkir — kafe, kedai, barista. */
    private static function kafe(CardGeometry $g): array
    {
        [$W, $H, $p, $T] = [$g->width(), $g->height(), $g->portrait(), $g->contentTop()];
        [$cx, $cy] = [$W - 14, $H - 14];

        return [self::c('umber-600'), [
            self::ring($cx, $cy, 10, 'bone-100', 0.5, ['opacity' => 0.35]),
            self::ring($cx + 1, $cy - 0.6, 9.2, 'bone-100', 0.25, ['opacity' => 0.25]),
            self::glyph('coffee', 6, $T + 1, 9, 'champagne-300'),
            ...self::stack(6, $H - ($p ? 36 : 19), 'bone-50', 'champagne-300', 'bone-200', $p ? 14 : 15, maxW: $p ? null : $W - 32),
        ], self::c('bone-100'), [self::ring(6, 6, 9, 'umber-600', 0.4, ['opacity' => 0.5])]];
    }

    /** Kontak: ikon telepon / email / web berderet — praktis untuk sales. */
    private static function kontak(CardGeometry $g): array
    {
        [$W, $H, $p, $T] = [$g->width(), $g->height(), $g->portrait(), $g->contentTop()];
        $rows = [['phone', '{{phone}}'], ['mail', '{{email}}'], ['globe', '{{website}}']];
        $y0 = $H - ($p ? 26 : 20);
        $list = [];
        foreach ($rows as $i => [$icon, $var]) {
            $y = $y0 + $i * 5.5;
            $list[] = self::glyph($icon, 6, $y - 2.6, 3.2, 'coral-700');
            $list[] = self::body($var, 11, $y, self::c('ink-950'), ['id' => $icon === 'mail' ? 'email' : ($icon === 'globe' ? 'website' : 'phone'), 'size' => 6.8, 'fitWidth' => $W - 17]);
        }

        return [self::c('bone-0'), [
            self::rect(6, $T + 1, 1.2, 12, 'coral-700', ['id' => 'accent']),
            self::text('{{name}}', 9.5, $T + 7, $p ? 13 : 15, self::c('ink-950'), ['id' => 'name', 'fitWidth' => $W - 21]), // berhenti sebelum ikon NFC
            self::label('{{title}}', 9.5, $T + 12.5, self::c('coral-700'), ['id' => 'title', 'fitWidth' => $W - 22]),
            ...$list,
        ], self::c('bone-0'), self::backBand($g, 'coral-700')];
    }

    /** Noktah: satu titik koral besar di samping nama — minimalis. */
    private static function noktah(CardGeometry $g): array
    {
        [$W, $H, $p, $T] = [$g->width(), $g->height(), $g->portrait(), $g->contentTop()];
        if ($p) {
            return [self::c('bone-50'), [
                self::ell(6, $T + 4, 9, 9, 'coral-700', ['id' => 'dot']),
                ...self::stack(6, $H / 2 + 6, 'ink-950', 'coral-700', 'umber-600', 14),
            ], self::c('coral-700'), []];
        }

        return [self::c('bone-50'), [
            self::ell(6, $H / 2 - 7, 9, 9, 'coral-700', ['id' => 'dot']),
            self::text('{{name}}', 19, $H / 2 - 0.5, 15, self::c('ink-950'), ['id' => 'name']),
            self::label('{{title}}', 19, $H / 2 + 5, self::c('coral-700'), ['id' => 'title', 'fitWidth' => $W - 23]),
            self::label('{{company}}', 6, $H - 7, self::c('umber-600'), ['id' => 'company', 'fitWidth' => $W - 12]),
        ], self::c('coral-700'), []];
    }

    /** Belah: kartu terbelah diagonal gading / hitam. */
    private static function belah(CardGeometry $g): array
    {
        [$W, $H, $p, $T] = [$g->width(), $g->height(), $g->portrait(), $g->contentTop()];

        return [self::c('bone-0'), [
            self::rect(-20, $H * ($p ? 0.58 : 0.62), $W + 40, $H, 'onyx-950', ['rotate' => $p ? -22 : -16, 'id' => 'split']),
            ...self::stack(6, $T + ($p ? 16 : 9), 'ink-950', 'bronze-700', 'umber-600', $p ? 14 : 15, maxW: $p ? $W - 12 : 52),
            self::label('{{phone}}', $W - 6, $H - 7, self::c('champagne-500'), ['id' => 'phone', 'align' => 'right', 'tracking' => 0.04]),
        ], self::c('onyx-950'), []];
    }

    /** Lapis: kartu kertas berlapis dengan bayangan solid. */
    private static function lapis(CardGeometry $g): array
    {
        [$W, $H, $T] = [$g->width(), $g->height(), $g->contentTop()];
        $y = max($T + 1, 11.0);
        $h = $H - $y - 7.5;

        return [self::c('rose-200'), [
            self::rect(7.5, $y + 1.5, $W - 13, $h, 'plum-800', ['rx' => 2]),
            self::rect(6, $y, $W - 13, $h, 'bone-0', ['rx' => 2, 'id' => 'paper']),
            ...self::stack(10, $y + $h - 14, 'ink-950', 'plum-800', 'umber-600', $g->portrait() ? 13 : 15, maxW: $W - 21),
        ], self::c('rose-200'), self::backBand($g, 'plum-800', 2.5)];
    }

    /** Mewah: bingkai champagne & berlian kecil di atas hitam. */
    private static function mewah(CardGeometry $g): array
    {
        [$W, $H, $p, $T] = [$g->width(), $g->height(), $g->portrait(), $g->contentTop()];
        $y = max($T, 3.5);
        $box = fn () => self::rect(3.5, $y, $W - 7, $H - $y - 3.5, 'champagne-500', ['fill' => 'none', 'stroke' => self::c('champagne-500'), 'strokeWidth' => 0.3]);
        $ny = $H / 2 + ($p ? 6 : 4);

        return [self::c('onyx-950'), [
            $box(),
            self::rect($W / 2 - 1.5, $ny - 12, 3, 3, 'champagne-500', ['rotate' => 45, 'id' => 'gem']),
            self::text('{{name}}', $W / 2, $ny, $p ? 14 : 16, self::c('bone-50'), ['id' => 'name', 'align' => 'center', 'weight' => 600]),
            self::label('{{title}}', $W / 2, $ny + 6, self::c('champagne-500'), ['id' => 'title', 'align' => 'center', 'fitWidth' => $W - 14]),
            self::label('{{company}}', $W / 2, $H - 8.5, self::c('ash-400'), ['id' => 'company', 'align' => 'center', 'fitWidth' => $W - 14]),
        ], self::c('onyx-950'), [$box()]];
    }

    /** Panji: umbul-umbul berekor dengan logo — partai, ormas, komunitas. */
    private static function panji(CardGeometry $g): array
    {
        [$W, $H, $p, $T] = [$g->width(), $g->height(), $g->portrait(), $g->contentTop()];
        $bottom = $p ? 37.0 : 40.0;
        $banner = fn (float $w, float $yb) => [
            self::rect(6, -3, $w, $yb + 3, 'coral-700', ['id' => 'banner']),
            // takik ekor: segitiga warna latar, alas di ujung bawah panji
            self::poly(6 + $w / 2 - $w * 0.577, $yb - $w * 0.385, $w * 1.155, $w * 0.513, 3, 'bone-0'),
        ];

        return [self::c('bone-0'), [
            ...$banner(13, $bottom),
            self::logo(7.5, $T + 1, 10, 10, ['mask' => 'circle']),
            ...($p ? self::stack(6, $bottom + 12, 'ink-950', 'coral-700', 'umber-600', 14)
                   : [
                       self::label('{{company}}', 25, $T + 6, self::c('coral-700'), ['id' => 'company', 'fitWidth' => 48]),
                       self::text('{{name}}', 25, $H - 19, 15, self::c('ink-950'), ['id' => 'name']),
                       self::label('{{title}}', 25, $H - 13.4, self::c('umber-600'), ['id' => 'title', 'fitWidth' => $W - 29]),
                   ]),
        ], self::c('bone-0'), $banner(8, 12)];
    }

    /** Anggota: kartu anggota dengan logo, nama organisasi, dan label ANGGOTA. */
    private static function anggota(CardGeometry $g): array
    {
        [$W, $H, $p, $T] = [$g->width(), $g->height(), $g->portrait(), $g->contentTop()];
        $pill = fn ($x, $y) => [
            self::rect($x, $y, 24, 6, 'mustard-400', ['rx' => 3, 'id' => 'pill']),
            self::label('ANGGOTA', $x + 12, $y + 4.2, self::c('ink-950'), ['id' => 'member', 'align' => 'center']),
        ];
        $rings = ['type' => 'rings', 'cx' => $W - 6, 'cy' => $H - 4, 'from' => 6, 'to' => 30, 'step' => 6, 'color' => self::c('champagne-300'), 'opacity' => 0.2];
        if ($p) {
            return [self::c('forest-800'), [
                $rings,
                self::logo($W / 2 - 8, $T + 2, 16, 16, ['mask' => 'circle']),
                self::label('{{company}}', $W / 2, $T + 25, self::c('champagne-300'), ['id' => 'company', 'align' => 'center', 'fitWidth' => $W - 12]),
                self::text('{{name}}', $W / 2, $T + 38, 14, self::c('bone-50'), ['id' => 'name', 'align' => 'center']),
                self::label('{{title}}', $W / 2, $T + 43.6, self::c('sage-300'), ['id' => 'title', 'align' => 'center', 'fitWidth' => $W - 12]),
                ...$pill($W / 2 - 12, $H - 16),
            ], self::c('forest-800'), [['type' => 'rings', 'cx' => 0, 'cy' => 0, 'from' => 6, 'to' => 18, 'step' => 4, 'color' => self::c('champagne-300'), 'opacity' => 0.25]]];
        }

        return [self::c('forest-800'), [
            $rings,
            self::logo(6, $T + 1, 12, 12, ['mask' => 'circle']),
            self::label('{{company}}', 21, $T + 8.5, self::c('champagne-300'), ['id' => 'company', 'fitWidth' => 52]),
            self::text('{{name}}', 6, $H - 22, 15, self::c('bone-50'), ['id' => 'name']),
            self::label('{{title}}', 6, $H - 16.6, self::c('sage-300'), ['id' => 'title', 'fitWidth' => 60]),
            ...$pill(6, $H - 12),
        ], self::c('forest-800'), [['type' => 'rings', 'cx' => 0, 'cy' => 0, 'from' => 6, 'to' => 18, 'step' => 4, 'color' => self::c('champagne-300'), 'opacity' => 0.25]]];
    }

    /** Neon: dua garis tepi koral & biru yang bergeser — event & hiburan. */
    private static function neon(CardGeometry $g): array
    {
        [$W, $H, $T] = [$g->width(), $g->height(), $g->contentTop()];
        $y = max($T + 1, 11.0);
        $h = $H - $y - 5;
        $o = fn ($x, $y, $c) => self::rect($x, $y, $W - 10, $h, $c, ['fill' => 'none', 'stroke' => self::c($c), 'strokeWidth' => 0.35, 'rx' => 2]);
        $ny = $y + $h / 2 + ($g->portrait() ? 0 : 1);

        return [self::c('onyx-950'), [
            $o(5, $y, 'coral-300'), $o(6, $y + 1, 'sky-300'),
            ...self::stack(10, $ny, 'bone-50', 'sky-300', 'coral-300', $g->portrait() ? 13 : 15, maxW: $W - 20),
        ], self::c('onyx-950'), [
            self::ln(-3, $H - 1.2, $W + 3, $H - 1.2, 'coral-300', 0.35),
            self::ln(-3, $H - 2.2, $W + 3, $H - 2.2, 'sky-300', 0.35),
        ]];
    }

    /** Sudut: dua siku tipis mengapit nama di tengah. */
    private static function sudut(CardGeometry $g): array
    {
        [$W, $H, $p] = [$g->width(), $g->height(), $g->portrait()];
        $brackets = [
            self::ln(5, 5, 13, 5, 'ink-950', 0.5), self::ln(5, 5, 5, 13, 'ink-950', 0.5),
            self::ln($W - 5, $H - 5, $W - 13, $H - 5, 'ink-950', 0.5), self::ln($W - 5, $H - 5, $W - 5, $H - 13, 'ink-950', 0.5),
        ];
        $cy = $H / 2 + ($p ? 3 : 1);

        return [self::c('bone-0'), [
            ...$brackets,
            ...self::stack($W / 2, $cy, 'ink-950', 'bronze-700', 'umber-600', $p ? 14 : 16, 'center', $W - 20),
        ], self::c('bone-0'), $brackets];
    }

    /** Segel: cap bergerigi dengan logo di tengah — sertifikasi & organisasi resmi. */
    private static function segel(CardGeometry $g): array
    {
        [$W, $H, $p, $T] = [$g->width(), $g->height(), $g->portrait(), $g->contentTop()];
        [$cx, $cy] = $p ? [$W / 2, $T + 20] : [$W - 20, $H / 2 + 3];
        $seal = [
            self::poly($cx - 15.5, $cy - 15.5, 31, 31, 12, 'bronze-700', ['star' => true, 'inner' => 0.88, 'opacity' => 0.14]),
            self::ring($cx, $cy, 13, 'bronze-700', 0.6),
            self::ring($cx, $cy, 11.3, 'bronze-700', 0.2),
            self::logo($cx - 8, $cy - 8, 16, 16, ['mask' => 'circle']),
        ];

        return [self::c('bone-50'), [
            ...$seal,
            ...($p ? self::stack($W / 2, $cy + 28, 'ink-950', 'bronze-700', 'umber-600', 14, 'center')
                   : [
                       self::label('{{company}}', 6, $T + 6, self::c('bronze-700'), ['id' => 'company', 'fitWidth' => 42]),
                       self::text('{{name}}', 6, $H - 19, 15, self::c('ink-950'), ['id' => 'name', 'fitWidth' => 42]),
                       self::label('{{title}}', 6, $H - 13.4, self::c('umber-600'), ['id' => 'title', 'fitWidth' => 42]),
                   ]),
        ], self::c('bone-50'), self::backBand($g, 'bronze-700')];
    }

    /** Kode: tipografi monospace ala terminal — developer & engineer. */
    private static function kode(CardGeometry $g): array
    {
        [$W, $H, $p, $T] = [$g->width(), $g->height(), $g->portrait(), $g->contentTop()];
        $mono = ['font' => 'IBM Plex Mono', 'weight' => 500];

        return [self::c('onyx-900'), [
            $p ? self::glyph('code', $W / 2 - 15, $T + 12, 30, 'onyx-700') : self::glyph('code', $W - 38, 16, 30, 'onyx-700'),
            self::label('// {{title}}', 6, $T + 8, self::c('sage-300'), ['id' => 'title', 'uppercase' => false, 'tracking' => 0.02, 'fitWidth' => $p ? $W - 12 : $W - 20]),
            self::text('>', 6, $H - ($p ? 22 : 16), $p ? 12 : 13, self::c('sage-300'), $mono + ['id' => 'prompt']),
            self::text('{{name}}', 11.5, $H - ($p ? 22 : 16), $p ? 12 : 13, self::c('bone-50'), $mono + ['id' => 'name']),
            self::label('{{email}}', 6, $H - ($p ? 16 : 10.5), self::c('sky-300'), ['id' => 'email', 'uppercase' => false, 'tracking' => 0.02, 'fitWidth' => $W - 12]),
        ], self::c('onyx-900'), self::backBand($g, 'sage-300', 1)];
    }

    /** Majalah: gaya sampul editorial — garis tebal, nama besar. */
    private static function majalah(CardGeometry $g): array
    {
        [$W, $H, $p, $T] = [$g->width(), $g->height(), $g->portrait(), $g->contentTop()];
        $ny = $p ? $H / 2 + 10 : $H - 14;

        return [self::c('bone-0'), [
            self::label('{{company}}', 6, $T + 5, self::c('ink-950'), ['id' => 'company', 'fitWidth' => $p ? $W - 12 : $W - 20]),
            self::rect(6, $T + 8, $W - 12, 1.2, 'ink-950', ['id' => 'rule']),
            self::label('No. 01', $W - 6, $T + 14, self::c('umber-600'), ['id' => 'issue', 'align' => 'right']),
            self::text('{{name}}', 6, $ny, $p ? 20 : 22, self::c('ink-950'), ['id' => 'name', 'weight' => 800]),
            self::body('{{title}}', 6, $ny + 6, self::c('coral-700'), ['id' => 'title', 'fitWidth' => $W - 12]),
            self::ln(6, $H - 4.5, $W - 6, $H - 4.5, 'ink-950', 0.25),
        ], self::c('bone-0'), [self::rect(-3, $H - 3, $W + 6, 1, 'ink-950')]];
    }

    /** Arsir: arsiran diagonal di satu sisi kartu sage. */
    private static function arsir(CardGeometry $g): array
    {
        [$W, $H, $p] = [$g->width(), $g->height(), $g->portrait()];
        $hatch = [];
        if ($p) {
            for ($y = $H - 30; $y <= $H + 12; $y += 2.5) {
                $hatch[] = self::ln(-3, $y, $W + 3, $y - 14, 'bone-50', 0.25, ['opacity' => 0.5]);
            }
        } else {
            for ($x = 6.0; $x <= 34; $x += 2.5) {
                $hatch[] = self::ln($x, -3, $x - 14, $H + 3, 'bone-50', 0.25, ['opacity' => 0.5]);
            }
        }

        return [self::c('sage-700'), [
            ...$hatch,
            ...($p ? self::stack(6, $H - 52, 'bone-50', 'mustard-400', 'bone-100', 14)
                   : self::stack(38, $H - 19, 'bone-50', 'mustard-400', 'bone-100', 15, maxW: $W - 42)),
        ], self::c('sage-700'), self::backBand($g, 'bone-50')];
    }
}
