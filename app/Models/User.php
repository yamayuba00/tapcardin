<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasPublicId, SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = ['remember_token', 'google_id', 'password'];

    protected function casts(): array
    {
        return [
            'notification_prefs' => 'array',
            'preferences' => 'array',
            'blocked_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'password_changed_at' => 'datetime',
        ];
    }

    public function personalTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'personal_tenant_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMember::class)->withoutGlobalScopes();
    }

    public function platformStaff(): HasOne
    {
        return $this->hasOne(PlatformStaff::class);
    }

    public function isBlocked(): bool
    {
        return $this->blocked_at !== null;
    }

    public function wantsNotification(string $type, string $channel, bool $default = true): bool
    {
        return (bool) data_get($this->notification_prefs, "$type.$channel", $default);
    }
}
