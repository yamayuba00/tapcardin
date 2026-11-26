<?php

namespace Tests\Feature;

use App\Enums\CardStatus;
use App\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Beranda: yang perlu ditindaklanjuti (follow-up terlambat, kartu fisik yang berakhir dalam 30 hari). */
class DashboardAttentionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_attention_counts_expiring_physical_cards_of_the_user(): void
    {
        $rina = $this->makeUser();
        $this->actingAs($rina);
        $this->getJson($this->host('app', '/api/dashboard'))->assertOk()
            ->assertJsonPath('data.attention.followups_overdue', 0)
            ->assertJsonPath('data.attention.cards_expiring', 0);

        $soon = $this->makeCard(CardStatus::Active, $rina);
        $soon->forceFill(['form' => Card::PHYSICAL, 'valid_until' => now()->addDays(10)])->save();
        $later = $this->makeCard(CardStatus::Active, $rina);
        $later->forceFill(['form' => Card::PHYSICAL, 'valid_until' => now()->addDays(120)])->save();

        $this->getJson($this->host('app', '/api/dashboard'))->assertOk()
            ->assertJsonPath('data.attention.cards_expiring', 1);
    }
}
