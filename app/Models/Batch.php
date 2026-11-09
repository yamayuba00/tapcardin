<?php

namespace App\Models;

use App\Enums\BatchStatus;
use App\Enums\ChipType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Batch produksi adalah objek platform (tanpa global scope tenant). */
class Batch extends Model
{
    use HasPublicId;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => BatchStatus::class,
            'chip_type' => ChipType::class,
            'completed_at' => 'datetime',
            'tokens_started_at' => 'datetime',
        ];
    }

    public function cards(): HasMany
    {
        return $this->hasMany(Card::class)->withoutGlobalScopes();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Item pesanan asal batch ini (null = batch stok / pilot / pengganti). */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'holder_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
