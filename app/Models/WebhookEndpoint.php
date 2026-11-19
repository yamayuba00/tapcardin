<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** URL tujuan webhook milik satu workspace; `secret` terenkripsi dan hanya ditampilkan sekali saat dibuat/dirotasi. */
class WebhookEndpoint extends Model
{
    use BelongsToTenant, HasPublicId;

    public const MAX_PER_WORKSPACE = 10;

    /** Gagal berturut-turut sebanyak ini → endpoint dinonaktifkan otomatis. */
    public const DISABLE_AFTER = 20;

    protected $guarded = ['id'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'is_active' => 'boolean',
            'secret' => 'encrypted',
            'disabled_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'endpoint_id');
    }

    public function listens(string $type): bool
    {
        return $type === 'ping' || in_array($type, $this->events ?? [], true);
    }
}
