<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Add-on berbayar di luar paket (mis. Integrasi CRM); `coming_soon` = pendaftaran minat saja. */
class Addon extends Model
{
    use HasPublicId;

    public const STATUSES = ['coming_soon', 'available', 'retired'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['features' => 'array', 'price_monthly' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    public function interests(): HasMany
    {
        return $this->hasMany(AddonInterest::class);
    }
}
