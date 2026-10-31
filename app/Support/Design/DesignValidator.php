<?php

namespace App\Support\Design;

/**
 * Pemeriksaan cetak (DBD §8): teks ≥ 6 pt, resolusi gambar ≥ 300 dpi, konten di dalam margin aman,
 * area lubang lanyard (kartu), dan elemen sistem tidak tertutup. Kotak pembatas memperhitungkan rotasi
 * & teks multi-baris; layer tersembunyi tidak dicetak sehingga dilewati.
 * Lanyard: pola berulang memanjang, jadi hanya batas melintang (atas/bawah tali) yang diperiksa.
 */
final class DesignValidator
{
    public const PT_MM = 0.3528;

    public const MIN_TEXT_PT = 6.0;

    public const MIN_DPI = 300;

    private const TYPE_LABELS = [
        'image' => 'Gambar', 'rect' => 'Kotak', 'line' => 'Garis', 'rings' => 'Cincin', 'circle' => 'Lingkaran', 'icon' => 'Ikon',
        'ellipse' => 'Elips', 'polygon' => 'Poligon', 'glyph' => 'Ikon',
    ];

    public function __construct(
        private readonly Geometry $g,
        private readonly array $vars = [],
        /** brand kit (BrandKit::toKit) — untuk resolusi logo `kit:logo` */
        private readonly ?array $kit = null,
    ) {}

    /** @return list<array{side:string,layer:?string,level:string,message:string}> */
    public function validate(array $front, array $back): array
    {
        return array_merge($this->side($front, 'front'), $this->side($back, 'back'));
    }

    public static function ok(array $issues): bool
    {
        return ! collect($issues)->contains('level', 'error');
    }

    private function side(array $side, string $which): array
    {
        $issues = [];
        // closure biasa dengan referensi: arrow function menangkap $issues by-value dan perubahan hilang
        $add = function (?string $id, string $level, string $msg) use (&$issues, $which) {
            $issues[] = ['side' => $which, 'layer' => $id, 'level' => $level, 'message' => $msg];
        };
        $layers = $side['layers'] ?? [];
        $lanyard = $this->g instanceof LanyardGeometry;
        $system = SystemLayers::boxes(array_filter($layers, fn ($l) => $l['locked'] ?? false));
        $slot = $this->g->slotZone();
        $s = $this->g->safe();
        $safe = ['x' => $s, 'y' => $s, 'w' => $this->g->width() - 2 * $s, 'h' => $this->g->height() - 2 * $s];
        $label = fn ($l) => $l['type'] === 'text' ? '"'.mb_strimwidth(str_replace("\n", ' ', $l['text']), 0, 24, '…').'"' : self::TYPE_LABELS[$l['type']] ?? $l['type'];

        foreach ($layers as $l) {
            if (($l['locked'] ?? false) || ($l['hidden'] ?? false)) {
                continue;
            }
            if ($l['type'] === 'text') {
                $l['text'] = Variables::fill($l['text'], $this->vars);
            }
            $id = $l['id'] ?? null;
            $box = self::box($l);
            $content = in_array($l['type'], ['text', 'image', 'glyph'], true);

            if ($l['type'] === 'text' && $l['size'] < self::MIN_TEXT_PT) {
                $add($id, 'error', "Teks {$label($l)} {$l['size']} pt terlalu kecil untuk dicetak (minimal 6 pt).");
            }
            if ($l['type'] === 'image') {
                $pxW = ($l['asset'] ?? '') === 'kit:logo' ? (int) ($this->kit['logo']['px_w'] ?? 0) : (int) ($l['px_w'] ?? 0);
                if ($pxW > 0 && $l['w'] > 0 && ($dpi = (int) round($pxW / ($l['w'] / 25.4))) < self::MIN_DPI) {
                    $add($id, 'warning', "Resolusi gambar {$dpi} dpi pada ukuran cetak; disarankan ≥ 300 dpi agar tidak pecah.");
                }
                if (($l['asset'] ?? '') === 'kit:logo' && empty($this->kit['logo'])) {
                    $add($id, 'warning', 'Logo brand kit belum diunggah, jadi tempat logo akan kosong saat dicetak.');
                }
            }
            if (! $box) {
                continue;
            }
            // pita selebar kartu yang tertembus lubang lanyard itu wajar; elemen lain diberi tahu
            $fullBand = $l['type'] === 'rect' && $l['w'] >= $this->g->width();
            if ($slot && ! $fullBand && self::intersects($box, $slot)) {
                $content
                    ? $add($id, 'error', "{$label($l)} berada di area lubang lanyard. Geser ke bawah.")
                    : $add($id, 'warning', "{$label($l)} akan terpotong lubang lanyard.");
            }
            if ($content && ! ($l['allowBleed'] ?? false)) {
                $outside = $lanyard
                    ? $box['y'] < $safe['y'] - 0.01 || $box['y'] + $box['h'] > $safe['y'] + $safe['h'] + 0.01
                    : ! self::inside($box, $safe);
                if ($outside) {
                    $add($id, 'warning', $lanyard
                        ? "{$label($l)} melewati tepi tali (margin {$s} mm) dan bisa terpotong jahitan."
                        : "{$label($l)} terlalu dekat tepi (keluar safe zone {$s} mm) dan bisa terpotong.");
                }
            }
            foreach ($system as $sys) {
                if ($content && self::intersects($box, $sys)) {
                    $add($id, 'warning', "{$label($l)} menutupi ".($sys['id'] === 'sys-qr' ? 'QR code' : 'zona tap NFC').'.');
                }
            }
        }

        return $issues;
    }

    /** Kotak pembatas perkiraan (mm) setelah rotasi. Lebar teks diperkirakan dari jumlah karakter. @return array{x:float,y:float,w:float,h:float}|null */
    public static function box(array $l): ?array
    {
        $b = match ($l['type']) {
            'text' => (function () use ($l) {
                $h = self::textSize($l) * self::PT_MM;
                $lines = explode("\n", $l['text']);
                $lh = $h * ($l['lineHeight'] ?? 1.25);
                $w = max(array_map(fn ($line) => self::textWidth(['text' => $line] + $l, $h), $lines));
                $x = match ($l['align'] ?? 'left') {
                    'center' => $l['x'] - $w / 2, 'right' => $l['x'] - $w, default => $l['x']
                };

                return ['x' => $x, 'y' => $l['y'] - $h * 0.8, 'w' => $w, 'h' => $h + $lh * (count($lines) - 1)];
            })(),
            'rect', 'image', 'ellipse', 'polygon' => ['x' => $l['x'], 'y' => $l['y'], 'w' => $l['w'], 'h' => $l['h']],
            'line' => ['x' => min($l['x1'], $l['x2']), 'y' => min($l['y1'], $l['y2']) - $l['width'] / 2,
                'w' => abs($l['x2'] - $l['x1']) ?: $l['width'], 'h' => abs($l['y2'] - $l['y1']) ?: $l['width']],
            'circle' => ['x' => $l['cx'] - $l['r'], 'y' => $l['cy'] - $l['r'], 'w' => $l['r'] * 2, 'h' => $l['r'] * 2],
            'icon', 'glyph' => ['x' => $l['x'], 'y' => $l['y'], 'w' => $l['size'], 'h' => $l['size']],
            default => null, // rings: dekoratif & sengaja meluber
        };

        return $b && ! empty($l['rotate']) ? self::rotated($b, (float) $l['rotate']) : $b;
    }

    /** Kotak pembatas sumbu-sejajar dari kotak yang diputar di sekitar pusatnya. */
    private static function rotated(array $b, float $deg): array
    {
        $r = deg2rad($deg);
        $w = abs($b['w'] * cos($r)) + abs($b['h'] * sin($r));
        $h = abs($b['w'] * sin($r)) + abs($b['h'] * cos($r));
        $cx = $b['x'] + $b['w'] / 2;
        $cy = $b['y'] + $b['h'] / 2;

        return ['x' => $cx - $w / 2, 'y' => $cy - $h / 2, 'w' => $w, 'h' => $h];
    }

    /** Ukuran efektif (pt) setelah auto-fit `fitWidth` — sama dengan textSize() di resources/js/lib/design.js. */
    public static function textSize(array $l): float
    {
        $size = (float) $l['size'];
        $fit = $l['fitWidth'] ?? null;
        if (! $fit || $size < self::MIN_TEXT_PT) {
            return $size;
        }
        $w = self::textWidth($l, $size * self::PT_MM);

        return $w > $fit ? max(self::MIN_TEXT_PT, round($size * $fit / $w, 1)) : $size;
    }

    private static function textWidth(array $l, float $h): float
    {
        return mb_strlen($l['text']) * $h * (($l['font'] ?? '') === 'IBM Plex Mono' ? 0.6 : 0.55) * (1 + ($l['tracking'] ?? 0));
    }

    private static function intersects(array $a, array $b): bool
    {
        return $a['x'] < $b['x'] + $b['w'] && $b['x'] < $a['x'] + $a['w'] && $a['y'] < $b['y'] + $b['h'] && $b['y'] < $a['y'] + $a['h'];
    }

    private static function inside(array $a, array $b): bool
    {
        return $a['x'] >= $b['x'] - 0.01 && $a['y'] >= $b['y'] - 0.01
            && $a['x'] + $a['w'] <= $b['x'] + $b['w'] + 0.01 && $a['y'] + $a['h'] <= $b['y'] + $b['h'] + 0.01;
    }
}
