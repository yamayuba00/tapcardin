<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Pesanan kartu fisik / lanyard. Alur status:
 *   pending_payment → paid → in_production → shipped → delivered   (atau cancelled sebelum produksi)
 */
class Order extends Model
{
    use BelongsToTenant, HasPublicId;

    public const LABELS = [
        'pending_payment' => 'Menunggu pembayaran', 'paid' => 'Dibayar — antre produksi', 'in_production' => 'Sedang diproduksi',
        'shipped' => 'Dikirim', 'delivered' => 'Diterima', 'cancelled' => 'Dibatalkan',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'subtotal' => 'integer', 'shipping_fee' => 'integer', 'total' => 'integer',
            'risk_score' => 'integer', 'risk_flags' => 'array', 'reviewed_at' => 'datetime',
            'paid_at' => 'datetime', 'production_started_at' => 'datetime', 'shipped_at' => 'datetime',
            'delivered_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class);
    }

    /** Invoice terbaru pesanan ini (dibuat ulang bila yang lama kedaluwarsa). */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class)->withoutGlobalScopes()->latestOfMany();
    }

    public function label(): string
    {
        return self::LABELS[$this->status] ?? $this->status;
    }
}
