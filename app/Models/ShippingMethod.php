<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Metode pengiriman pesanan (tarif datar per pesanan) — dikelola di panel Katalog. */
class ShippingMethod extends Model
{
    use HasPublicId;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['price' => 'integer', 'needs_address' => 'boolean', 'is_active' => 'boolean'];
    }
}
