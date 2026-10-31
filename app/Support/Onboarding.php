<?php

namespace App\Support;

use App\Enums\CardStatus;
use App\Enums\TenantType;
use App\Models\Card;
use App\Models\ProductEvent;
use App\Models\Profile;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Panduan pengguna baru di Beranda: lengkapi bio → (personal) atur @nama → bagikan kartu digital → kartu fisik.
 * Setiap langkah dihitung dari data nyata (bukan centang manual), jadi selesai dengan sendirinya.
 * Disembunyikan bila semua selesai atau pengguna menutupnya (users.preferences.onboarding_dismissed_at).
 */
final class Onboarding
{
    /** Butir kelengkapan bio kartu utama → persentase. */
    private const PROFILE_ITEMS = [
        'avatar' => 'Foto profil', 'title' => 'Jabatan / profesi', 'bio' => 'Bio singkat',
        'phone' => 'WhatsApp atau telepon', 'email' => 'Email', 'link' => 'Minimal satu tautan (website / sosial media)',
    ];

    public static function for(User $user, Tenant $tenant): ?array
    {
        if (! empty($user->preferences['onboarding_dismissed_at'])) {
            return null;
        }
        $cards = Card::acrossTenants()->where(['tenant_id' => $tenant->id, 'holder_user_id' => $user->id])
            ->whereIn('status', [CardStatus::Active->value, CardStatus::Paused->value])->orderByRaw("form = 'digital' desc")->orderBy('activated_at')->get();
        $main = $cards->first();
        $profile = $main?->profile_id ? Profile::withoutGlobalScopes()->with('fields')->find($main->profile_id) : null;

        $types = $profile ? $profile->fields->pluck('type')->all() : [];
        $has = [
            'avatar' => (bool) $profile?->avatar_path,
            'title' => filled($profile?->title),
            'bio' => filled($profile?->bio),
            'phone' => (bool) array_intersect($types, ['whatsapp', 'phone']),
            'email' => in_array('email', $types, true),
            'link' => (bool) array_diff($types, ['whatsapp', 'phone', 'email']),
        ];
        $items = collect(self::PROFILE_ITEMS)->map(fn ($label, $k) => ['key' => $k, 'label' => $label, 'done' => $has[$k]])->values();
        $percent = (int) round($items->where('done', true)->count() / $items->count() * 100);

        $shared = $cards->contains(fn (Card $c) => $c->last_tap_at !== null)
            || ProductEvent::where(['user_id' => $user->id, 'name' => 'card_shared'])->exists();
        $physical = $cards->contains(fn (Card $c) => ! $c->isDigital());

        $steps = array_values(array_filter([
            ['key' => 'profile', 'title' => 'Lengkapi bio link', 'text' => 'Foto, jabatan, kontak, dan tautan — inilah yang dilihat orang saat kartu Anda di-tap.',
                'done' => $percent >= 80, 'cta' => $main ? ['label' => 'Atur bio', 'to' => "/cards/{$main->card_token}/bio"] : null],
            $tenant->type === TenantType::Personal ? ['key' => 'handle', 'title' => 'Pilih nama pengguna', 'text' => 'Dapatkan tautan pendek tap…/@nama yang mudah diingat & ditulis di mana saja.',
                'done' => filled($user->handle), 'cta' => ['label' => 'Atur @nama', 'to' => '/settings']] : null,
            ['key' => 'share', 'title' => 'Bagikan kartu digital', 'text' => 'Tunjukkan QR di layar, kirim lewat WhatsApp, atau unduh gambarnya — tanpa menunggu kartu fisik.',
                'done' => $shared, 'cta' => $main ? ['label' => 'Bagikan', 'to' => "/cards/{$main->card_token}/share"] : null],
            ['key' => 'physical', 'title' => 'Punya kartu fisik', 'text' => 'Desain kartu NFC Anda di Studio, atau aktifkan kartu yang sudah Anda terima.',
                'done' => $physical, 'cta' => ['label' => 'Desain kartu', 'to' => '/designs'], 'secondary' => ['label' => 'Aktifkan kartu', 'to' => '/cards']],
        ]));
        $done = count(array_filter($steps, fn ($s) => $s['done']));

        return $done === count($steps) ? null : [
            'steps' => $steps, 'done' => $done, 'total' => count($steps),
            'profile' => ['percent' => $percent, 'items' => $items->all(), 'avatar_url' => $profile?->avatar_path ? Storage::disk('public')->url($profile->avatar_path) : null],
        ];
    }
}
