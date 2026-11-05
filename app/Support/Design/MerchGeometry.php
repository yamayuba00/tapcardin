<?php

namespace App\Support\Design;

/**
 * Area cetak merchandise (sablon DTF / bordir / sublimasi mug) dalam mm. Tanpa bleed; margin aman 5 mm agar
 * elemen tidak terlalu mepet ke tepi area cetak. Latar sisi = warna bahan/produk (hanya untuk pratinjau).
 */
final class MerchGeometry implements Geometry
{
    public const SAFE = 5.0;

    public function __construct(public readonly float $w, public readonly float $h) {}

    public function kind(): string
    {
        return 'merch';
    }

    public function width(): float
    {
        return $this->w;
    }

    public function height(): float
    {
        return $this->h;
    }

    public function bleed(): float
    {
        return 0.0;
    }

    public function safe(): float
    {
        return self::SAFE;
    }

    public function portrait(): bool
    {
        return $this->h > $this->w;
    }

    public function slotZone(): ?array
    {
        return null;
    }

    public function size(): array
    {
        return ['w' => $this->w, 'h' => $this->h, 'bleed' => 0, 'unit' => 'mm'];
    }

    public function zones(): array
    {
        return ['safe' => self::SAFE, 'print' => true];
    }

    /** Desain awal: nama merek/nama di tengah area cetak, ukuran teks mengikuti lebar area. */
    public function starter(string $text, string $background, string $ink): array
    {
        $size = round(min(72, max(10, $this->w / 6)), 1);

        return [
            'size' => $this->size(), 'zones' => $this->zones(),
            'background' => ['type' => 'color', 'value' => $background],
            'layers' => [
                ['id' => 'brand', 'type' => 'text', 'text' => $text, 'x' => $this->w / 2, 'y' => round($this->h / 2 + $size * 0.127, 2), 'size' => $size,
                    'font' => 'Syne', 'weight' => 700, 'color' => $ink, 'align' => 'center', 'uppercase' => true],
            ],
        ];
    }
}
