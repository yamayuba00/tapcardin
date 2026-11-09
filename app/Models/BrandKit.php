<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/** Identitas merek bersama kartu & lanyard: warna, logo, nama, tagline. */
class BrandKit extends Model
{
    use BelongsToTenant, HasPublicId, SoftDeletes;

    protected $guarded = ['id'];

    public const COLOR_KEYS = ['primary', 'secondary', 'background', 'text'];

    protected function casts(): array
    {
        return ['is_workspace' => 'boolean']; // kit workspace: logo organisasi, dipakai semua anggota (WorkspaceBrandController)
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function designs(): HasMany
    {
        return $this->hasMany(Design::class);
    }

    /** Bentuk yang dipakai editor & renderer server (warna per kunci rujukan `kit:*`). */
    public function toKit(): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'colors' => [
                'primary' => $this->primary_color, 'secondary' => $this->secondary_color,
                'background' => $this->background_color, 'text' => $this->text_color,
            ],
            'logo' => $this->logo_path ? ['asset' => $this->logo_path, 'url' => Storage::disk('public')->url($this->logo_path), 'px_w' => $this->logo_px_w, 'px_h' => $this->logo_px_h] : null,
            'display_name' => $this->display_name,
            'tagline' => $this->tagline,
            'website' => $this->website,
            'workspace' => (bool) $this->is_workspace,
        ];
    }
}
