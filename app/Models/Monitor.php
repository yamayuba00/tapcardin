<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Monitor uptime (HTTP/HTTPS + kata kunci, atau masa berlaku SSL) — dicek oleh `tapcard:uptime`. */
class Monitor extends Model
{
    use HasPublicId;

    public const TYPES = ['http', 'ssl'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean', 'is_active' => 'boolean',
            'last_checked_at' => 'datetime', 'next_check_at' => 'datetime', 'last_change_at' => 'datetime', 'ssl_expires_at' => 'datetime',
        ];
    }

    public function checks(): HasMany
    {
        return $this->hasMany(MonitorCheck::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(MonitorEvent::class);
    }

    /** Nama di halaman status publik. */
    public function publicName(): string
    {
        return $this->public_name ?: $this->name;
    }
}
