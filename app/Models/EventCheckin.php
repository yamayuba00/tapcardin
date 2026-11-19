<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kehadiran peserta event: satu baris per kartu per event. `user_id` hanya untuk pemegang kartu di workspace ini;
 * kartu dari workspace lain dicatat sebagai tamu dengan nama publik profilnya saja (`guest_name`).
 */
class EventCheckin extends Model
{
    use BelongsToTenant, HasPublicId;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class)->withoutGlobalScopes();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }
}
