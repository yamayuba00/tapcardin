<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array'];
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function actorStaff(): BelongsTo
    {
        return $this->belongsTo(StaffAccount::class, 'actor_user_id');
    }

    /** Email pelaku — `actor_type` menentukan tabel asal id (staff_accounts / users). */
    public function actorEmail(): ?string
    {
        return ($this->actor_type === 'staff' ? $this->actorStaff : $this->actorUser)?->email;
    }
}
