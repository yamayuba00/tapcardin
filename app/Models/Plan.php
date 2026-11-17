<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['limits' => 'array', 'features' => 'array', 'per_seat' => 'boolean', 'is_public' => 'boolean'];
    }

    /** Siklus tagihan yang boleh dipilih: perusahaan hanya per 6 bulan. */
    public function cycles(): array
    {
        return $this->tenant_type === 'company' ? ['semiannual'] : ['monthly', 'yearly'];
    }

    public function isFree(): bool
    {
        return collect($this->cycles())->every(fn ($c) => $this->priceFor($c) === 0);
    }

    public function priceFor(string $cycle): int
    {
        return (int) match ($cycle) {
            'yearly' => $this->price_yearly,
            'semiannual' => $this->price_semiannual,
            default => $this->price_monthly,
        };
    }
}
