<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Desain kartu, lanyard, atau merchandise (BSD §4.4, format JSON §5). Lanyard menunjuk kartu pasangannya lewat
 * `paired_design_id` (index unik: satu kartu ↔ satu lanyard); keduanya bisa berbagi satu brand kit.
 */
class Design extends Model
{
    use BelongsToTenant, HasPublicId, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'front_json' => 'array',
            'back_json' => 'array',
            'variables' => 'array',
            'locked_layer_ids' => 'array',
            'lanyard' => 'boolean',
            'is_template' => 'boolean',
            'is_published' => 'boolean',
            'validated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function brandKit(): BelongsTo
    {
        return $this->belongsTo(BrandKit::class);
    }

    /** Untuk lanyard: kartu pasangannya. */
    public function pairedCard(): BelongsTo
    {
        return $this->belongsTo(self::class, 'paired_design_id');
    }

    /** Untuk kartu: lanyard yang dipasangkan dengannya. */
    public function pairedLanyard(): HasOne
    {
        return $this->hasOne(self::class, 'paired_design_id');
    }

    /** Sudah dipesan cetak (pesanan yang belum dibatalkan) → tidak boleh dihapus; isi cetak mengacu desain ini. */
    public function isOrdered(): bool
    {
        return OrderItem::where('design_id', $this->id)
            ->whereIn('order_id', Order::withoutGlobalScopes()->where('status', '!=', 'cancelled')->select('id'))
            ->exists();
    }

    public function lanyardWidth(): BelongsTo
    {
        return $this->belongsTo(LanyardWidth::class);
    }

    public function merchProduct(): BelongsTo
    {
        return $this->belongsTo(MerchProduct::class);
    }

    public function lanyardHardware(): BelongsTo
    {
        return $this->belongsTo(LanyardHardware::class);
    }
}
