<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Notifikasi in-app; dibaca SSE dengan kursor id (TRD §9). */
class Notification extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'read_at' => 'datetime'];
    }
}
