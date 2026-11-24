<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Tarif ekspedisi per zona, kurir, dan layanan. `is_placeholder` = belum diisi tarif sungguhan. */
class ShippingZoneRate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['base_cost' => 'integer', 'per_kg_cost' => 'integer', 'etd_min_days' => 'integer', 'etd_max_days' => 'integer', 'is_placeholder' => 'boolean'];
    }
}
