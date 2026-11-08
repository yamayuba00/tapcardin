<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Perubahan status monitor (mis. up → down) — dasar notifikasi & riwayat gangguan. */
class MonitorEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
