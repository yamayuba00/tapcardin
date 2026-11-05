<?php

namespace App\Models;

use App\Support\Bio\BioBlocks;
use App\Support\Bio\BioTheme;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Halaman bio link milik satu profil (= satu kartu). Maks. 3 per profil, tepat satu aktif — yang tampil saat kartu
 * di-tap. Tema halaman aktif dicerminkan ke `profiles.theme` agar semua pembaca tema lama (kartu, preview) tetap benar.
 */
class BioPage extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['theme' => 'array', 'is_active' => 'boolean'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(BioBlock::class)->orderBy('sort')->orderBy('id');
    }

    /** Profil tanpa halaman (dibuat sebelum fitur ini / jalur pembuatan lain) → halaman "Utama" berisi tombol simpan kontak & daftar kontak. true = baru dibuat. */
    public static function ensureFor(Profile $profile): bool
    {
        return DB::transaction(function () use ($profile) {
            Profile::whereKey($profile->id)->lockForUpdate()->first();
            if (self::where('profile_id', $profile->id)->exists()) {
                return false;
            }
            $page = self::create([
                'tenant_id' => $profile->tenant_id, 'profile_id' => $profile->id, 'name' => 'Utama',
                'is_active' => true, 'theme' => BioTheme::normalize($profile->theme),
            ]);
            $page->addStarterBlocks();

            return true;
        });
    }

    public function addStarterBlocks(): void
    {
        foreach (BioBlocks::starter() as $sort => $b) {
            $this->blocks()->create(['tenant_id' => $this->tenant_id, 'sort' => $sort] + $b);
        }
    }

    /** Jadikan halaman ini satu-satunya yang aktif (nonaktifkan yang lain dulu agar unique index parsial terpenuhi). */
    public function activate(): void
    {
        DB::transaction(function () {
            self::where('profile_id', $this->profile_id)->whereKeyNot($this->id)->where('is_active', true)->update(['is_active' => false]);
            $this->forceFill(['is_active' => true])->save();
            Profile::whereKey($this->profile_id)->update(['theme' => json_encode(BioTheme::normalize($this->theme)), 'updated_at' => now()]);
        });
    }

    /** Salin halaman ini (tema + blok) ke profil tujuan. */
    public function duplicateTo(Profile $to, string $name, bool $active = false): self
    {
        $copy = self::create([
            'tenant_id' => $to->tenant_id, 'profile_id' => $to->id, 'name' => $name,
            'is_active' => $active, 'theme' => BioTheme::normalize($this->theme),
        ]);
        foreach (BioBlock::acrossTenants()->where('bio_page_id', $this->id)->orderBy('sort')->orderBy('id')->get() as $b) {
            $copy->blocks()->create(['tenant_id' => $to->tenant_id, 'type' => $b->type, 'data' => $b->data, 'is_visible' => $b->is_visible, 'sort' => $b->sort]);
        }

        return $copy;
    }

    /** Bentuk untuk editor: data mentah tiap blok. */
    public function present(): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'is_active' => $this->is_active,
            'theme' => BioTheme::normalize($this->theme),
            'blocks' => $this->blocks->map(fn (BioBlock $b) => [
                'id' => $b->public_id,
                'type' => $b->type,
                'is_visible' => $b->is_visible,
                'data' => $b->data,
            ])->values()->all(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
