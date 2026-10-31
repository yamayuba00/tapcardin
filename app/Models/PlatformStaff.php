<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformStaff extends Model
{
    protected $table = 'platform_staff';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_reverified_at' => 'datetime', 'ip_allowlist' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
