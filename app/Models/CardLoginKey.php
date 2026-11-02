<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Kunci login kartu NFC: PIN (hash) pemegang untuk satu kartu fisik. */
class CardLoginKey extends Model
{
    use HasPublicId;

    protected $guarded = ['id'];

    protected $hidden = ['pin_hash'];

    protected function casts(): array
    {
        return [
            'locked_until' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class)->withoutGlobalScopes();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
