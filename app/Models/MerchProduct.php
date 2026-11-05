<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Produk merchandise yang bisa didesain (jaket, kaos, tote bag, mug, topi): area cetak mm & jumlah sisi. */
class MerchProduct extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'print_w_mm' => 'integer', 'print_h_mm' => 'integer', 'print_sides' => 'integer'];
    }

    public function present(): array
    {
        return [
            'key' => $this->key, 'name' => $this->name, 'category' => $this->category, 'silhouette' => $this->silhouette,
            'print_w_mm' => $this->print_w_mm, 'print_h_mm' => $this->print_h_mm, 'print_sides' => $this->print_sides,
            'description' => $this->description, 'coming_soon' => $this->status === 'coming_soon',
        ];
    }

    /** Katalog editor: produk aktif berurutan. @return list<array> */
    public static function catalog(): array
    {
        return self::where('is_active', true)->orderBy('sort')->get()->map->present()->all();
    }
}
