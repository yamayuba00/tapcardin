<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Voucher diskon checkout paket. */
class Voucher extends Model
{
    use HasPublicId;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['plan_keys' => 'array', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_active' => 'boolean'];
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(VoucherRedemption::class);
    }

    /** Diskon (rupiah) untuk harga tertentu; tidak pernah melebihi harga. */
    public function discountFor(int $price): int
    {
        $d = $this->kind === 'percent' ? (int) floor($price * $this->value / 100) : (int) $this->value;

        return max(0, min($price, $d));
    }
}
