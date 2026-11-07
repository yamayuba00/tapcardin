<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Kartu yang dibawa ke sebuah event: selama event berlangsung, tap & koneksi dari kartu ini tercatat ke event. */
class EventCard extends Model
{
    use BelongsToTenant;

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
}
