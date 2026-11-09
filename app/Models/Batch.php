<?php

namespace App\Models;

use App\Enums\BatchStatus;
use App\Enums\ChipType;
use App\Enums\TenantType;
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
            'uniform' => 'boolean',
        ];
    }

    /** Jalur kartu: dijual langsung (stok desain seragam), custom perorangan, atau perusahaan. */
    public const CHANNELS = ['direct' => 'Jual langsung', 'custom' => 'Custom', 'company' => 'Perusahaan'];

    /** Ditentukan otomatis dari asal batch — tidak perlu diatur. */
    public function channel(): string
    {
        return match (true) {
            $this->uniform => 'direct',
            $this->tenant_id && $this->tenant?->type !== TenantType::Personal => 'company',
            default => 'custom',
        };
    }

    /** Desain depan & belakang stok jual langsung (milik akun petugas; dibaca lintas workspace). */
    public function design(): BelongsTo
    {
        return $this->belongsTo(Design::class)->withoutGlobalScopes();
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
