<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    public const CREATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::find($key)?->value['v'] ?? $default;
    }

    public static function put(string $key, mixed $value, ?int $by = null): void
    {
        static::updateOrCreate(['key' => $key], ['value' => ['v' => $value], 'updated_by' => $by]);
    }
}
