<?php

namespace App\Models;

use App\Enums\TapSource;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Tap extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['source' => TapSource::class, 'saved_contact' => 'boolean', 'created_at' => 'datetime'];
    }
}
