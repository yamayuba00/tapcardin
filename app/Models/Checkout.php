<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Draf checkout wizard pesanan cetak: item keranjang terpilih, alamat, promo. Pesanan dibuat saat dibayar. */
class Checkout extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['cart_ids' => 'array', 'shipping' => 'array', 'expires_at' => 'datetime'];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
