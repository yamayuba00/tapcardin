<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Katalog lebar tali lanyard + harga per pcs (1 / 2 sisi). */
class LanyardWidth extends Model
{
    use HasPublicId;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
