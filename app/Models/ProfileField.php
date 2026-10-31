<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileField extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'is_primary' => 'boolean',
            'is_visible' => 'boolean',
            'in_vcard' => 'boolean',
            'is_locked' => 'boolean',
        ];
    }

    /** `type` → contact_types.key */
    public function contactType(): BelongsTo
    {
        return $this->belongsTo(ContactType::class, 'type', 'key');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
