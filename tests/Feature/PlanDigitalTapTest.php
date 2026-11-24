<?php

namespace Tests\Feature;

use App\Authz\PlanFeatures;
use App\Enums\CardStatus;
use App\Models\Plan;
use App\Support\TapQuota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Paket perorangan: Gratis 1 kartu aktif; Pro tap kartu digital tanpa batas, Gratis tetap kuota. */
class PlanDigitalTapTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function userOn(string $planKey): array
    {
        $user = $this->makeUser();
        $tenant = $user->personalTenant;
        $tenant->update(['plan_id' => Plan::where('key', $planKey)->value('id')]);
        PlanFeatures::forget($tenant);
        $card = $this->makeCard(CardStatus::Active, $user);
        $card->update(['tap_quota' => 1, 'tap_used' => 1]);

        return [$card->fresh(), $tenant];
    }

    public function test_free_plan_allows_one_active_card(): void
    {
        $this->assertSame(1, Plan::where('key', 'personal_free')->value('limits')['cards_active'] ?? null);
    }

    public function test_free_digital_taps_stop_at_quota(): void
    {
        [$card, $tenant] = $this->userOn('personal_free');
        $this->assertFalse(TapQuota::consume($card->id, $card->holder_user_id, $tenant->id));
        $this->assertFalse(TapQuota::available($card->id, $tenant->id));
    }

    public function test_pro_digital_taps_are_unlimited_and_still_counted(): void
    {
        [$card, $tenant] = $this->userOn('personal_pro');
        $this->assertTrue(TapQuota::consume($card->id, $card->holder_user_id, $tenant->id));
        $this->assertTrue(TapQuota::consume($card->id, $card->holder_user_id, $tenant->id));
        $this->assertTrue(TapQuota::available($card->id, $tenant->id));
        $this->assertSame(3, (int) $card->fresh()->tap_used, 'tap tetap dihitung untuk statistik');
        $this->assertNull(TapQuota::of($card->fresh())['quota']);
    }
}
