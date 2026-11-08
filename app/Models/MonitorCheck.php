<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Satu hasil cek monitor (disimpan 90 hari). */
class MonitorCheck extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['checked_at' => 'datetime'];
    }
}
