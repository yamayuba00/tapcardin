<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Domain kustom workspace (alias {slug}.domain.com) — aktif setelah TXT `_tapcard.{domain}` = token terverifikasi. */
class TenantDomain extends Model
{
    use HasPublicId;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime', 'last_checked_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function txtName(): string
    {
        return '_tapcard.'.$this->domain;
    }

    public function txtValue(): string
    {
        return 'tapcard-verify='.$this->verify_token;
    }
}
