<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Tagihan (BSD `invoices`): langganan paket; pesanan kartu/lanyard menyusul modul pesanan. */
class Invoice extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'lines' => 'array', 'billing_snapshot' => 'array',
            'issued_at' => 'datetime', 'due_at' => 'datetime', 'paid_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class);
    }

    public function isPayable(): bool
    {
        return in_array($this->status, ['issued', 'overdue'], true);
    }
}
