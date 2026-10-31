<?php

namespace App\Support\Design;

use App\Models\LanyardHardware;
use App\Models\LanyardWidth;

/** Katalog & perkiraan harga lanyard dari tabel `lanyard_widths` + `lanyard_hardware` (server = satu sumber harga). */
final class LanyardPricing
{
    public static function catalog(): array
    {
        return [
            'length_mm' => LanyardGeometry::LENGTH,
            'tile' => ['min' => LanyardGeometry::TILE_MIN, 'max' => LanyardGeometry::TILE_MAX, 'default' => 150],
            'safe' => LanyardGeometry::SAFE,
            'widths' => LanyardWidth::where('is_active', true)->orderBy('sort')->get()->map(fn (LanyardWidth $w) => [
                'width_mm' => $w->width_mm, 'label' => $w->label, 'price_single' => $w->price_single, 'price_double' => $w->price_double, 'min_qty' => $w->min_qty,
            ])->values()->all(), // array biasa, bukan Collection: hasil ini ikut di-cache (serializable_classes=false)
            'hardware' => LanyardHardware::where('is_active', true)->orderBy('sort')->get()->map(fn (LanyardHardware $h) => [
                'key' => $h->key, 'name' => $h->name, 'description' => $h->description, 'price' => $h->price,
            ])->values()->all(),
        ];
    }

    /** @return array{unit:int, total:int, qty:int, min_qty:int, breakdown:list<array{label:string, amount:int}>} */
    public static function quote(int $widthMm, string $hardware, int $sides, int $qty): array
    {
        $w = LanyardWidth::where('width_mm', $widthMm)->where('is_active', true)->firstOrFail();
        $h = LanyardHardware::where('key', $hardware)->where('is_active', true)->firstOrFail();
        $print = $sides === 2 ? $w->price_double : $w->price_single;
        $qty = max($qty, $w->min_qty);
        $unit = $print + $h->price;

        return [
            'unit' => $unit, 'qty' => $qty, 'min_qty' => $w->min_qty, 'total' => $unit * $qty,
            'breakdown' => [
                ['label' => "Tali {$w->label}, cetak {$sides} sisi", 'amount' => $print],
                ['label' => $h->name, 'amount' => $h->price],
            ],
        ];
    }
}
