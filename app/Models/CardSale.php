<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Penjualan kartu langsung (aktivasi di tempat); pembayaran ditandai staf: QRIS atau manual. */
class CardSale extends Model
{
    use HasPublicId;

    public const METHODS = ['qris' => 'QRIS', 'manual' => 'Manual / tunai'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime', 'amount' => 'integer'];
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class)->withoutGlobalScopes();
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_user_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sold_by_user_id');
    }

    /** Status bayar: paid → wajib metode & waktu (sesuai CHECK di tabel). */
    public function markPayment(string $status, ?string $method): void
    {
        $this->forceFill($status === 'paid'
            ? ['status' => 'paid', 'payment_method' => $method, 'paid_at' => $this->paid_at ?? now()]
            : ['status' => 'unpaid', 'payment_method' => $method, 'paid_at' => null])->save();
    }

    public function present(): array
    {
        return [
            'id' => $this->public_id,
            'amount' => $this->amount,
            'status' => $this->status,
            'payment_method' => $this->payment_method,
            'payment_label' => $this->payment_method ? self::METHODS[$this->payment_method] : null,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'note' => $this->note,
            'buyer' => $this->buyer ? ['id' => $this->buyer->public_id, 'name' => $this->buyer->name, 'email' => $this->buyer->email] : null,
            'seller' => $this->seller?->name,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
