<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** AFD §9 — pelacakan produk server-side tanpa skrip pihak ketiga. */
class ProductEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['props' => 'array'];
    }

    public static function record(string $name, ?int $tenantId = null, ?int $userId = null, array $props = []): void
    {
        static::create(['name' => $name, 'tenant_id' => $tenantId, 'user_id' => $userId, 'props' => $props]);
    }
}
