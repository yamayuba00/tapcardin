<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** LD-05 — pengingat follow-up; `is_auto` = dibuat otomatis N jam setelah lead baru. */
class LeadReminder extends Model
{
    use BelongsToTenant, HasPublicId;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'done_at' => 'datetime', 'notified_at' => 'datetime', 'is_auto' => 'boolean'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
