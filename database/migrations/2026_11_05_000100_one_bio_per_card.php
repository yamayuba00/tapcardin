<?php

use App\Actions\Profiles\ProvisionCardProfile;
use App\Models\Card;
use App\Models\Profile;
use App\Models\Tenant;
use App\Support\Bio\BioTheme;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Migrations\Migration;

/**
 * Bio link kini milik masing-masing kartu (1 kartu = 1 profil). Kartu yang dulu berbagi satu
 * profil mendapat salinannya sendiri (kartu tertua tetap memegang aslinya), dan tema lama
 * `{template, mode, bg, accent}` dirapikan ke bentuk preset BioTheme.
 */
return new class extends Migration
{
    public function up(): void
    {
        $current = app(CurrentTenant::class);

        Profile::acrossTenants()->withTrashed()->each(function (Profile $p) {
            $p->timestamps = false;
            $p->update(['theme' => BioTheme::normalize($p->theme)]);
        });

        $shared = Card::acrossTenants()->whereNotNull('profile_id')
            ->whereIn('status', ProvisionCardProfile::OWNING)
            ->orderBy('id')->get()->groupBy('profile_id')->filter(fn ($g) => $g->count() > 1);

        foreach ($shared as $profileId => $cards) {
            $profile = Profile::acrossTenants()->find($profileId);
            if (! $profile) {
                continue;
            }
            $current->run(Tenant::find($profile->tenant_id), function () use ($profile, $cards) {
                foreach ($cards->slice(1) as $card) {
                    $copy = ProvisionCardProfile::copy($profile, ProvisionCardProfile::label($card));
                    $card->timestamps = false;
                    $card->update(['profile_id' => $copy->id]);
                }
            });
        }
    }

    public function down(): void
    {
        // salinan profil tetap ada; tidak ada yang perlu dikembalikan
    }
};
