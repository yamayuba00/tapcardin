<?php

namespace App\Models;

use Illuminate\Support\Str;

/** BSD §1 — ID numerik tidak pernah muncul di URL; entitas publik memakai ULID. */
trait HasPublicId
{
    protected static function bootHasPublicId(): void
    {
        static::creating(function ($model) {
            $model->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
