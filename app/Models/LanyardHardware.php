<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Katalog pengait lanyard (lobster, J-hook, badge reel, pengaman putus) + tambahan harga. */
class LanyardHardware extends Model
{
    use HasPublicId;

    protected $table = 'lanyard_hardware';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
