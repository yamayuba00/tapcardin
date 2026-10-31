<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Jenis kontak/tautan bio link; `profile_fields.type` → `contact_types.key`. */
class ContactType extends Model
{
    use HasPublicId;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ContactTypeGroup::class, 'group_id');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(ProfileField::class, 'type', 'key');
    }
}
