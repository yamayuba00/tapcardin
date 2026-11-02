<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Kode QR berjangka kartu digital (paket dengan qr_rotation_days): /s/{code} berlaku sampai expires_at. */
class CardShareCode extends Model
{
    use HasPublicId;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class)->withoutGlobalScopes();
    }
}
