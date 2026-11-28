<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Baris pesanan: kartu (produk + desain) atau lanyard (lebar, pengait, sisi cetak + desain), dengan snapshot desain. */
class OrderItem extends Model
{
    use HasPublicId;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['design_snapshot' => 'array', 'quantity' => 'integer', 'unit_price' => 'integer', 'total' => 'integer',
            'print_starts_on' => 'date', 'print_eta_min' => 'date', 'print_eta_max' => 'date', 'print_delay_days' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withoutGlobalScopes();
    }

    public function cardProduct(): BelongsTo
    {
        return $this->belongsTo(CardProduct::class);
    }

    public function lanyardWidth(): BelongsTo
    {
        return $this->belongsTo(LanyardWidth::class);
    }

    public function lanyardHardware(): BelongsTo
    {
        return $this->belongsTo(LanyardHardware::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }
}
