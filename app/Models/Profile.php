<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Profile extends Model
{
    use BelongsToTenant, HasPublicId, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'theme' => 'array',
            'is_indexable' => 'boolean',
            'show_exchange_form' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fields(): HasMany
    {
        return $this->hasMany(ProfileField::class)->orderBy('sort')->orderBy('id');
    }

    public function cards(): HasMany
    {
        return $this->hasMany(Card::class);
    }
}
