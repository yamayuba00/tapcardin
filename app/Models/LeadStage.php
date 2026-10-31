<?php

namespace App\Models;

use App\Enums\StageKind;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class LeadStage extends Model
{
    use BelongsToTenant, HasPublicId;

    public $timestamps = false;

    protected $guarded = ['id'];

    /** BSD §4.6 — dibuat saat tenant dibuat. Warna = nama token swatch (DBD §3.5). */
    public const DEFAULTS = [
        ['name' => 'Baru', 'kind' => 'open', 'color' => 'sky', 'is_default' => true],
        ['name' => 'Dihubungi', 'kind' => 'open', 'color' => 'champagne-300', 'is_default' => false],
        ['name' => 'Meeting', 'kind' => 'open', 'color' => 'champagne-500', 'is_default' => false],
        ['name' => 'Proposal', 'kind' => 'open', 'color' => 'amber', 'is_default' => false],
        ['name' => 'Menang', 'kind' => 'won', 'color' => 'sage', 'is_default' => false],
        ['name' => 'Kalah', 'kind' => 'lost', 'color' => 'coral', 'is_default' => false],
    ];

    protected function casts(): array
    {
        return ['kind' => StageKind::class, 'is_default' => 'boolean'];
    }
}
