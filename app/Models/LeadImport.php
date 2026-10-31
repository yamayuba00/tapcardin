<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** LD-10 — satu file CSV yang diimpor ke koneksi. */
class LeadImport extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['headers' => 'array', 'mapping' => 'array', 'errors' => 'array', 'finished_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
