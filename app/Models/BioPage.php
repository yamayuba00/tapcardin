<?php

namespace App\Models;

use App\Support\Bio\BioBlocks;
use App\Support\Bio\BioTheme;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

    /** Profil tanpa halaman (dibuat sebelum fitur ini / jalur pembuatan lain) → halaman "Utama" berisi blok kontak. true = baru dibuat. */
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
            $page->blocks()->create(['tenant_id' => $profile->tenant_id, 'type' => 'contacts', 'data' => [], 'sort' => 0]);

            return true;
        });
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

    /** Salin halaman ini (tema + blok + file gambar baru) ke profil tujuan. */
    public function duplicateTo(Profile $to, string $name, bool $active = false): self
    {
        $copy = self::create([
            'tenant_id' => $to->tenant_id, 'profile_id' => $to->id, 'name' => $name,
            'is_active' => $active, 'theme' => BioTheme::normalize($this->theme),
        ]);
        foreach (BioBlock::acrossTenants()->where('bio_page_id', $this->id)->orderBy('sort')->orderBy('id')->get() as $b) {
            $data = $b->data;
            if ($b->type === 'gallery') {
                $data['images'] = array_values(array_filter(array_map(
                    fn ($img) => ($path = self::copyImage($img['path'], $to->tenant_id)) ? ['path' => $path] + $img : null,
                    $data['images'] ?? [],
                )));
                if ($data['images'] === []) {
                    continue; // file asli sudah hilang → blok kosong tidak disalin
                }
            }
            $copy->blocks()->create(['tenant_id' => $to->tenant_id, 'type' => $b->type, 'data' => $data, 'is_visible' => $b->is_visible, 'sort' => $b->sort]);
        }

        return $copy;
    }

    /** Hapus file galeri yang tidak lagi dipakai halaman mana pun dari profil ini. */
    public static function pruneImages(int $profileId, array $before): void
    {
        $gone = array_diff($before, self::imagesOf($profileId));
        if ($gone) {
            Storage::disk('public')->delete(array_values($gone));
        }
    }

    /** Semua path gambar galeri milik profil. @return list<string> */
    public static function imagesOf(int $profileId): array
    {
        $pages = self::acrossTenants()->where('profile_id', $profileId)->pluck('id');

        return BioBlocks::paths(BioBlock::acrossTenants()->whereIn('bio_page_id', $pages)->where('type', 'gallery')->get(['type', 'data'])->toArray());
    }

    private static function copyImage(string $path, int $tenantId): ?string
    {
        $disk = Storage::disk('public');
        if (! $disk->exists($path)) {
            return null;
        }
        $to = "t$tenantId/bio/".Str::random(32).'.webp';
        $disk->copy($path, $to);

        return $to;
    }

    /** Bentuk untuk editor: data mentah + URL gambar untuk preview. */
    public function present(): array
    {
        $disk = Storage::disk('public');

        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'is_active' => $this->is_active,
            'theme' => BioTheme::normalize($this->theme),
            'blocks' => $this->blocks->map(fn (BioBlock $b) => [
                'id' => $b->public_id,
                'type' => $b->type,
                'is_visible' => $b->is_visible,
                'data' => $b->type === 'gallery'
                    ? ['layout' => $b->data['layout'] ?? 'grid', 'images' => array_map(fn ($i) => $i + ['url' => $disk->url($i['path'])], $b->data['images'] ?? [])]
                    : $b->data,
            ])->values()->all(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
