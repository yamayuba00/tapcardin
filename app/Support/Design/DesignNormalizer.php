<?php

namespace App\Support\Design;

use Illuminate\Support\Facades\Storage;

/**
 * Menerima JSON desain dari Studio (tidak dipercaya) dan menyaringnya ke format BSD §5 yang aman:
 *  - warna hanya hex solid (tanpa gradien/bayangan/blur) atau rujukan brand kit `kit:primary|secondary|background|text`;
 *  - tipe layer dibatasi, angka di-clamp, teks maks 300 karakter (boleh multi-baris);
 *  - aset gambar harus milik tenant ini (atau `kit:logo`);
 *  - elemen sistem terkunci (QR, NFC, TapCard) dibangun ulang dari geometri kartu — lanyard & merchandise tidak punya.
 * Properti umum semua layer: rotate (°), opacity, hidden, lock (dikunci pengguna), group, name.
 */
final class DesignNormalizer
{
    public const MAX_LAYERS = 80;

    public const FONTS = ['Syne' => [600, 700, 800], 'IBM Plex Sans' => [400, 500, 600], 'IBM Plex Mono' => [400, 500]];

    public const KIT_COLORS = ['primary', 'secondary', 'background', 'text'];

    /** Ikon lucide yang boleh dipakai pengguna (layer `glyph`); dirender sama di editor & cetak. */
    public const GLYPHS = [
        'phone', 'mail', 'globe', 'map-pin', 'message-circle', 'send', 'instagram', 'linkedin', 'facebook', 'youtube', 'twitter', 'github',
        'at-sign', 'building-2', 'briefcase', 'user', 'users', 'calendar', 'clock', 'star', 'heart', 'award', 'shield-check', 'check',
        'leaf', 'coffee', 'camera', 'music-2', 'shopping-bag', 'store', 'wallet', 'landmark', 'qr-code', 'scan-line', 'wifi', 'zap',
        'sparkles', 'gem', 'crown', 'rocket', 'lightbulb', 'palette', 'pen-tool', 'code', 'stethoscope', 'graduation-cap', 'scale', 'home',
    ];

    public function __construct(
        private readonly Geometry $g,
        private readonly int $tenantId,
        /** warna brand kit (kunci → hex) untuk kontras elemen sistem bila latar memakai rujukan kit */
        private readonly array $kitColors = [],
    ) {}

    public function side(array $input, string $which): array
    {
        $background = $this->background($input['background'] ?? []);
        $layers = [];
        foreach (array_slice($input['layers'] ?? [], 0, self::MAX_LAYERS) as $i => $raw) {
            if (! is_array($raw) || str_starts_with((string) ($raw['id'] ?? ''), SystemLayers::PREFIX)) {
                continue; // elemen sistem tidak pernah diterima dari klien
            }
            $layer = $this->layer($raw);
            if ($layer) {
                $layers[] = ['id' => $this->id($raw['id'] ?? null, $i)] + $layer + $this->common($raw);
            }
        }

        if ($this->g->kind() !== 'card') { // lanyard & merchandise tidak punya elemen sistem
            return ['size' => $this->g->size(), 'background' => $background, 'layers' => $layers, 'zones' => $this->g->zones()];
        }
        $side = TemplateLibrary::side($this->g, $this->hexOf($background['value'] ?? null) ?? Palette::hex('bone-0'), $layers, $which);
        $side['background'] = $background; // simpan bentuk asli (rujukan kit / gambar), kontras sistem memakai hex hasil resolve

        return $side;
    }

    private function background(array $bg): array
    {
        if (($bg['type'] ?? 'color') === 'image') {
            $asset = $this->asset((string) ($bg['asset'] ?? ''));
            if ($asset) {
                return ['type' => 'image', 'asset' => $asset, 'url' => Storage::disk('public')->url($asset),
                    'value' => $this->color($bg['value'] ?? null, 'bone-0'), 'fit' => ($bg['fit'] ?? 'cover') === 'contain' ? 'contain' : 'cover'];
            }
        }

        return ['type' => 'color', 'value' => $this->color($bg['value'] ?? null, 'bone-0')];
    }

    private function layer(array $l): ?array
    {
        $n = fn (string $k, float $min, float $max, float $default = 0) => $this->clamp($l[$k] ?? $default, $min, $max);
        $span = max($this->g->width(), $this->g->height());
        $xy = fn (string $k) => $n($k, -$this->g->bleed() - 60, $span + 60);
        $wh = fn (string $k, float $default = 10) => $n($k, 0.2, $span + 20, $default);
        $color = fn (string $k, string $fallback = 'ink-950') => $this->color($l[$k] ?? null, $fallback);
        $stroke = fn () => isset($l['stroke']) && ($l['strokeWidth'] ?? 0) > 0
            ? ['stroke' => $color('stroke'), 'strokeWidth' => $n('strokeWidth', 0.1, 5, 0.3)] : [];

        return match ($l['type'] ?? null) {
            'text' => $this->text($l, $xy, $n, $color),
            'rect' => ['type' => 'rect', 'x' => $xy('x'), 'y' => $xy('y'), 'w' => $wh('w'), 'h' => $wh('h'),
                'fill' => isset($l['fill']) && $l['fill'] === 'none' ? 'none' : $color('fill'), 'rx' => $n('rx', 0, 50)] + $stroke(),
            'ellipse' => ['type' => 'ellipse', 'x' => $xy('x'), 'y' => $xy('y'), 'w' => $wh('w'), 'h' => $wh('h'),
                'fill' => isset($l['fill']) && $l['fill'] === 'none' ? 'none' : $color('fill')] + $stroke(),
            'polygon' => ['type' => 'polygon', 'x' => $xy('x'), 'y' => $xy('y'), 'w' => $wh('w'), 'h' => $wh('h'),
                'sides' => (int) $n('sides', 3, 12, 6), 'star' => ! empty($l['star']), 'inner' => $n('inner', 0.2, 0.9, 0.5),
                'fill' => isset($l['fill']) && $l['fill'] === 'none' ? 'none' : $color('fill')] + $stroke(),
            'line' => ['type' => 'line', 'x1' => $xy('x1'), 'y1' => $xy('y1'), 'x2' => $xy('x2'), 'y2' => $xy('y2'),
                'color' => $color('color'), 'width' => $n('width', 0.1, 5, 0.3), 'dash' => ! empty($l['dash'])],
            'circle' => ['type' => 'circle', 'cx' => $xy('cx'), 'cy' => $xy('cy'), 'r' => $n('r', 0.5, 60, 5),
                'stroke' => $color('stroke'), 'strokeWidth' => $n('strokeWidth', 0.1, 5, 0.3)],
            'rings' => ['type' => 'rings', 'cx' => $xy('cx'), 'cy' => $xy('cy'), 'from' => $n('from', 1, 80, 6), 'to' => $n('to', 2, 120, 30),
                'step' => $n('step', 1, 30, 4), 'color' => $color('color'), 'opacity' => $n('opacity', 0.05, 1, 0.3)],
            'glyph' => in_array($l['name'] ?? '', self::GLYPHS, true)
                ? ['type' => 'glyph', 'name' => $l['name'], 'x' => $xy('x'), 'y' => $xy('y'), 'size' => $n('size', 2, 60, 6),
                    'color' => $color('color'), 'strokeWidth' => $n('strokeWidth', 1, 3, 2)]
                : null,
            'image' => $this->image($l, $xy, $wh, $n),
            default => null,
        };
    }

    /** Properti yang berlaku untuk semua jenis layer. */
    private function common(array $l): array
    {
        return array_filter([
            'rotate' => isset($l['rotate']) && (float) $l['rotate'] != 0.0 ? $this->clamp($l['rotate'], -180, 180) : null,
            'opacity' => isset($l['opacity']) && (float) $l['opacity'] < 1 ? $this->clamp($l['opacity'], 0.05, 1) : null,
            'hidden' => ! empty($l['hidden']) ?: null,
            'lock' => ! empty($l['lock']) ?: null,
            'group' => isset($l['group']) && is_string($l['group']) && $l['group'] !== '' ? mb_substr(preg_replace('/[^A-Za-z0-9_-]/', '', $l['group']), 0, 24) : null,
            'name' => isset($l['name']) && is_string($l['name']) && ($l['type'] ?? '') !== 'glyph' ? mb_substr(trim($l['name']), 0, 40) ?: null : null,
        ], fn ($v) => $v !== null);
    }

    private function text(array $l, callable $xy, callable $n, callable $color): array
    {
        $font = array_key_exists($l['font'] ?? '', self::FONTS) ? $l['font'] : 'IBM Plex Sans';
        $weights = self::FONTS[$font];
        $weight = in_array((int) ($l['weight'] ?? 0), $weights, true) ? (int) $l['weight'] : $weights[0];
        $text = mb_substr(str_replace("\r", '', (string) ($l['text'] ?? '')), 0, 300);
        $multi = str_contains($text, "\n");

        return array_filter([
            'type' => 'text',
            'text' => $text,
            'x' => $xy('x'), 'y' => $xy('y'),
            'size' => $n('size', 4, 120, 8),
            'font' => $font, 'weight' => $weight,
            'color' => $color('color'),
            'align' => in_array($l['align'] ?? '', ['left', 'center', 'right'], true) ? $l['align'] : 'left',
            'uppercase' => ! empty($l['uppercase']) ?: null,
            'italic' => ! empty($l['italic']) ?: null,
            'tracking' => isset($l['tracking']) ? $n('tracking', 0, 0.5) : null,
            'lineHeight' => $multi && isset($l['lineHeight']) ? $n('lineHeight', 0.8, 3, 1.25) : null,
            // lebar maks (mm): teks yang lebih panjang diperkecil otomatis, tidak di bawah 6 pt
            'fitWidth' => isset($l['fitWidth']) && ! $multi ? $n('fitWidth', 5, 300) : null,
            'allowBleed' => ! empty($l['allowBleed']) ?: null,
        ], fn ($v) => $v !== null);
    }

    private function image(array $l, callable $xy, callable $wh, callable $n): ?array
    {
        $ref = (string) ($l['asset'] ?? '');
        $asset = $ref === 'kit:logo' ? $ref : $this->asset($ref);
        if (! $asset) {
            return null;
        }

        return array_filter([
            'type' => 'image', 'asset' => $asset, 'url' => $asset === 'kit:logo' ? null : Storage::disk('public')->url($asset),
            'x' => $xy('x'), 'y' => $xy('y'), 'w' => $wh('w'), 'h' => $wh('h'),
            'px_w' => (int) ($l['px_w'] ?? 0), 'px_h' => (int) ($l['px_h'] ?? 0),
            'fit' => ($l['fit'] ?? 'contain') === 'cover' ? 'cover' : 'contain',
            'mask' => in_array($l['mask'] ?? 'none', ['circle', 'rounded'], true) ? $l['mask'] : null,
            'radius' => ($l['mask'] ?? null) === 'rounded' ? $n('radius', 0.5, 30, 2) : null,
        ], fn ($v) => $v !== null);
    }

    private function asset(string $asset): ?string
    {
        return str_starts_with($asset, "t{$this->tenantId}/designs/") && ! str_contains($asset, '..') && Storage::disk('public')->exists($asset)
            ? $asset : null;
    }

    /** hex solid atau rujukan kit; selain itu warna cadangan dari palet. */
    private function color(mixed $v, string $fallback): string
    {
        if (is_string($v) && preg_match('/^kit:(primary|secondary|background|text)$/', $v)) {
            return $v;
        }

        return Palette::isHex($v) ? strtoupper($v) : Palette::hex($fallback);
    }

    private function hexOf(?string $v): ?string
    {
        if ($v && str_starts_with($v, 'kit:')) {
            return $this->kitColors[substr($v, 4)] ?? null;
        }

        return Palette::isHex($v) ? strtoupper($v) : null;
    }

    private function clamp(mixed $v, float $min, float $max): float
    {
        return round(min(max(is_numeric($v) ? (float) $v : $min, $min), $max), 2);
    }

    private function id(mixed $id, int $i): string
    {
        $id = is_string($id) ? preg_replace('/[^A-Za-z0-9_-]/', '', $id) : '';

        return $id !== '' ? mb_substr($id, 0, 24) : 'l'.($i + 1);
    }
}
