<?php

namespace App\Support\Design;

/**
 * Pita lanyard cetak sublimasi: lebar 15/20/25 mm × panjang 900 mm (dua sisi). Yang didesain adalah
 * satu pola (tile) sepanjang `tile` mm yang diulang di sepanjang tali — koordinat x sepanjang tali, y melintang.
 * Margin aman 1,5 mm dari tepi jahitan; bleed 1 mm.
 */
final class LanyardGeometry implements Geometry
{
    public const LENGTH = 900.0;

    public const BLEED = 1.0;

    public const SAFE = 1.5;

    public const TILE_MIN = 40;

    public const TILE_MAX = 300;

    public function __construct(public readonly float $widthMm = 20.0, public readonly float $tile = 150.0) {}

    public function kind(): string
    {
        return 'lanyard';
    }

    public function width(): float
    {
        return $this->tile;
    }

    public function height(): float
    {
        return $this->widthMm;
    }

    public function bleed(): float
    {
        return self::BLEED;
    }

    public function safe(): float
    {
        return self::SAFE;
    }

    public function portrait(): bool
    {
        return false;
    }

    public function slotZone(): ?array
    {
        return null;
    }

    public function size(): array
    {
        return ['w' => $this->tile, 'h' => $this->widthMm, 'bleed' => self::BLEED, 'unit' => 'mm', 'length' => self::LENGTH];
    }

    public function zones(): array
    {
        return ['safe' => self::SAFE, 'repeat' => true];
    }

    /** Pola awal: teks merek di tengah + dua titik di ujung pola; ukuran teks menyesuaikan lebar tali. */
    public function starterTile(string $text, string $background, string $ink): array
    {
        $mid = $this->widthMm / 2;
        $size = round(min(11, $this->widthMm * 0.55), 2);
        $end = $this->tile - 8;

        return [
            'size' => $this->size(), 'zones' => $this->zones(),
            'background' => ['type' => 'color', 'value' => $background],
            'layers' => [
                ['id' => 'brand', 'type' => 'text', 'text' => $text, 'x' => $this->tile / 2, 'y' => round($mid + $size * 0.127, 2), 'size' => $size,
                    'font' => 'Syne', 'weight' => 700, 'color' => $ink, 'align' => 'center', 'tracking' => 0.12, 'uppercase' => true],
                ['id' => 'dot1', 'type' => 'ellipse', 'x' => 6, 'y' => $mid - 1, 'w' => 2, 'h' => 2, 'fill' => $ink],
                ['id' => 'dot2', 'type' => 'ellipse', 'x' => $end, 'y' => $mid - 1, 'w' => 2, 'h' => 2, 'fill' => $ink],
            ],
        ];
    }

    /** Berapa kali pola terulang di sepanjang tali. */
    public function repeats(): int
    {
        return (int) ceil(self::LENGTH / $this->tile);
    }
}
