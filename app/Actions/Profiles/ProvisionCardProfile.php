<?php

namespace App\Actions\Profiles;

use App\Enums\CardStatus;
use App\Models\BioPage;
use App\Models\Card;
use App\Models\Profile;
use App\Models\User;
use App\Support\Bio\BioTheme;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Setiap kartu punya bio link sendiri (1 kartu = 1 profil). Saat aktivasi:
 *  1. pakai profil milik pengguna yang belum dipakai kartu mana pun (mis. profil awal saat daftar);
 *  2. jika tidak ada, salin bio terakhir yang diubah agar pengguna tidak mengetik ulang;
 *  3. jika belum punya profil sama sekali, buat baru.
 * Harus dipanggil di dalam konteks tenant tujuan.
 */
final class ProvisionCardProfile
{
    public function handle(User $user, Card $card): Profile
    {
        $mine = Profile::where('user_id', $user->id)->whereNull('archived_at');

        $unused = (clone $mine)->whereDoesntHave('cards', fn ($c) => $c->whereKeyNot($card->id)
            ->whereIn('status', self::OWNING))->orderBy('id')->first();
        if ($unused) {
            return $unused;
        }

        $latest = (clone $mine)->latest('updated_at')->latest('id')->first(); // seri waktu → profil terbaru

        return $latest
            ? self::copy($latest, self::label($card))
            : Profile::create([
                'user_id' => $user->id,
                'label' => self::label($card),
                'display_name' => $user->name,
                'theme' => BioTheme::normalize(null),
                'locale' => $user->locale ?? 'id',
            ]);
    }

    /** Status kartu yang "memiliki" bio-nya; kartu dicabut/dikembalikan melepas bio. */
    public const OWNING = [CardStatus::Active->value, CardStatus::Paused->value, CardStatus::Lost->value];

    /** Salinan penuh: identitas, tema, field, dan file foto baru (bukan berbagi path). */
    public static function copy(Profile $from, string $label): Profile
    {
        $disk = Storage::disk('public');
        $dup = function (?string $path) use ($disk) {
            if (! $path || ! $disk->exists($path)) {
                return null;
            }
            $to = dirname($path).'/'.Str::random(32).'.'.pathinfo($path, PATHINFO_EXTENSION);
            $disk->copy($path, $to);

            return $to;
        };

        $copy = $from->replicate(['public_id', 'avatar_path', 'vcard_photo_path']);
        $copy->fill(['label' => $label, 'avatar_path' => $dup($from->avatar_path), 'vcard_photo_path' => $dup($from->vcard_photo_path)]);
        $copy->save();

        foreach ($from->fields()->get() as $f) {
            $field = $f->replicate(['public_id']);
            $field->profile_id = $copy->id;
            $field->save();
        }
        foreach (BioPage::acrossTenants()->where('profile_id', $from->id)->orderBy('id')->get() as $page) {
            $page->duplicateTo($copy, $page->name, $page->is_active);
        }

        return $copy;
    }

    public static function label(Card $card): string
    {
        return mb_substr($card->label ?: 'Kartu '.$card->formattedShortCode(), 0, 40);
    }
}
