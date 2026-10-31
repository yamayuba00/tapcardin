<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataExport extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
