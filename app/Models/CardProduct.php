<?php

namespace App\Models;

use App\Enums\ChipType;
use Illuminate\Database\Eloquent\Model;

/** Produk kartu fisik yang bisa dipesan (bahan & chip) beserta harga per kartu — dikelola di panel Katalog. */
class CardProduct extends Model
{
    use HasPublicId;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['chip_type' => ChipType::class, 'price' => 'integer', 'min_qty' => 'integer', 'print_min_days' => 'integer', 'print_max_days' => 'integer', 'is_active' => 'boolean'];
    }
}
