<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'settings' => 'array', 'checkin_enabled' => 'boolean'];
    }

    /** Kartu yang dibawa ke event ini (tabel event_cards). */
    public function cards(): HasMany
    {
        return $this->hasMany(EventCard::class);
    }

    public function connections(): HasMany
    {
        return $this->hasMany(Connection::class)->whereNull('merged_into_id');
    }
}
