<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

/** Akun staf panel pemilik — terpisah total dari pengguna app. (tabel users), login & sesi sendiri (guard `staff`). */
class StaffAccount extends Authenticatable
{
    use HasPublicId;

    protected $guarded = ['id'];

    protected $hidden = ['remember_token', 'password'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'password_changed_at' => 'datetime',
            'last_reverified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'ip_allowlist' => 'array',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** Akses staf dicabut lewat `status` (dicek EnsurePlatformStaff), bukan blokir pengguna. */
    public function isBlocked(): bool
    {
        return false;
    }
}
