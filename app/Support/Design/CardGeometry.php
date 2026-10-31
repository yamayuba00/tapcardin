<?php

namespace App\Support\Design;

/**
 * DBD §8.1 — CR80 85,6 × 54 mm, bleed 3 mm, safe zone 4 mm. Koordinat mm relatif tepi potong.
 * Lanyard: bagian atas ±12 mm dicadangkan untuk lubang slot (panduan vendor ID badge: sisakan ~0,5" di atas).
 */
final class CardGeometry implements Geometry
{
    public const BLEED = 3.0;

    public const SAFE = 4.0;

    public const SLOT_W = 16.0;   // lebar area cadangan slot (lubang ±13,5 mm + toleransi)

    public const SLOT_H_LANDSCAPE = 10.0;

    public const SLOT_H_PORTRAIT = 12.0;

    public function __construct(public readonly string $orientation = 'landscape', public readonly bool $lanyard = false) {}

    public function kind(): string
    {
        return 'card';
    }

    public function bleed(): float
    {
        return self::BLEED;
    }

    public function safe(): float
    {
        return self::SAFE;
    }

    public function width(): float
    {
        return $this->orientation === 'portrait' ? 54.0 : 85.6;
    }

    public function height(): float
    {
        return $this->orientation === 'portrait' ? 85.6 : 54.0;
    }

    public function portrait(): bool
    {
        return $this->orientation === 'portrait';
    }

    /** Area yang tidak boleh berisi teks/gambar/QR saat kartu dipakai dengan lanyard. @return array{x:float,y:float,w:float,h:float}|null */
    public function slotZone(): ?array
    {
        if (! $this->lanyard) {
            return null;
        }
        $h = $this->portrait() ? self::SLOT_H_PORTRAIT : self::SLOT_H_LANDSCAPE;

        return ['x' => round(($this->width() - self::SLOT_W) / 2, 2), 'y' => -self::BLEED, 'w' => self::SLOT_W, 'h' => $h + self::BLEED];
    }

    /** Batas atas konten aman (di bawah area slot bila lanyard). */
    public function contentTop(): float
    {
        return $this->lanyard ? ($this->portrait() ? self::SLOT_H_PORTRAIT : self::SLOT_H_LANDSCAPE) + 1 : self::SAFE;
    }

    public function size(): array
    {
        return ['w' => $this->width(), 'h' => $this->height(), 'bleed' => self::BLEED, 'unit' => 'mm'];
    }

    public function zones(): array
    {
        return array_filter([
            'safe' => self::SAFE,
            'slot' => $this->slotZone(),
            // DBD §8.1 — inlay chip di tengah kartu (aman dicetak di PVC; hindari foil logam luas)
            'chip' => $this->portrait()
                ? ['x' => 10.0, 'y' => 25.0, 'w' => 34.0, 'h' => 45.0]
                : ['x' => 20.0, 'y' => 10.0, 'w' => 45.0, 'h' => 34.0],
        ]);
    }
}
