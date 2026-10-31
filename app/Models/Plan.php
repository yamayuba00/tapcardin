<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['limits' => 'array', 'features' => 'array', 'per_seat' => 'boolean', 'is_public' => 'boolean'];
    }
}
