<?php

namespace Tests\Feature;

use App\Enums\CardStatus;
use App\Models\Card;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** PRD §9.1 / AFD F-05 — dua faktor: kartu fisik + (pembeli ATAU kode aktivasi). */
class ActivationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function activate(string $token, ?string $code = null)
    {
        return $this->postJson($this->host('app', '/api/cards/activate'), ['token' => $token, 'code' => $code]);
    }

    public function test_activation_api_requires_login(): void
    {
        $card = $this->makeCard();
        $this->getJson($this->host('app', "/api/cards/{$card->card_token}/activation"))->assertUnauthorized();
        $this->get($this->host('app', "/activate/{$card->card_token}"))->assertOk()->assertSee('id="app"', false);
    }

    public function test_activation_info_reports_buyer(): void
    {
        $buyer = $this->makeUser();
        $card = $this->makeCard(CardStatus::Unclaimed, buyer: $buyer);
        $this->actingAs($buyer)->getJson($this->host('app', "/api/cards/{$card->card_token}/activation"))
            ->assertOk()->assertJsonPath('data.is_buyer', true)->assertJsonPath('data.status', 'unclaimed');
    }

    public function test_buyer_activates_without_code(): void
    {
        $buyer = $this->makeUser();
        $card = $this->makeCard(CardStatus::Unclaimed, buyer: $buyer);

        $this->actingAs($buyer)->activate($card->card_token)
            ->assertOk()->assertJsonPath('data.card.status', 'active')
            ->assertJsonPath('data.next', "/cards/{$card->card_token}/setup");

        $card->refresh();
        $this->assertSame(CardStatus::Active, $card->status);
        $this->assertSame($buyer->id, $card->holder_user_id);
        $this->assertSame($buyer->personal_tenant_id, $card->tenant_id);
        // profil awal dipegang kartu digital; kartu fisik mendapat salinan bio terakhir (1 kartu = 1 bio)
        $this->assertNotSame($this->profileOf($buyer)->id, $card->profile_id);
        $this->assertSame($buyer->name, $card->profile->display_name);
        $this->assertNull($card->activation_code_hash, 'kode sekali pakai');
        $this->assertDatabaseHas('card_status_history', ['card_id' => $card->id, 'from_status' => 'unclaimed', 'to_status' => 'active']);
        $this->assertDatabaseHas('product_events', ['name' => 'card_activated']);
    }

    public function test_gift_recipient_activates_with_code_and_card_moves_to_their_workspace(): void
    {
        $buyer = $this->makeUser('buyer@example.com', 'Pembeli');
        $friend = $this->makeUser('teman@example.com', 'Teman');
        $card = $this->makeCard(CardStatus::Unclaimed, buyer: $buyer);

        $this->actingAs($friend)->activate($card->card_token)->assertStatus(422)->assertJsonPath('error.code', 'activation_code_required');
        $this->actingAs($friend)->activate($card->card_token, 'tc·7k2m-9qxp')->assertOk();
        $this->assertSame($friend->personal_tenant_id, $card->fresh()->tenant_id);
    }

    public function test_wrong_code_counts_down_then_locks_for_an_hour(): void
    {
        $user = $this->makeUser();
        $card = $this->makeCard();

        for ($i = 1; $i <= 4; $i++) {
            $this->actingAs($user)->activate($card->card_token, 'AAAAAAAA')
                ->assertStatus(422)->assertJsonPath('error.code', 'activation_code_invalid')
                ->assertJsonPath('error.message', 'Kode belum cocok. Sisa '.(5 - $i).' percobaan.');
        }
        $this->actingAs($user)->activate($card->card_token, 'AAAAAAAA')->assertStatus(423)->assertJsonPath('error.code', 'activation_locked');
        $this->assertTrue($card->fresh()->activation_locked_until->isFuture());

        // kode benar pun ditolak selama terkunci (throttle per kartu juga 5/jam)
        $this->travel(61)->minutes();
        $this->actingAs($user)->activate($card->card_token, self::ACTIVATION_CODE)->assertOk();
    }

    public function test_plan_limit_on_active_cards(): void
    {
        $user = $this->makeUser();
        $limit = Plan::where('key', 'personal_free')->first()->limits['cards_active'];
        for ($i = 0; $i < $limit; $i++) {
            $this->makeCard(CardStatus::Active, $user);
        }
        $card = $this->makeCard(CardStatus::Unclaimed, buyer: $user);
        $this->actingAs($user)->activate($card->card_token)->assertStatus(402)->assertJsonPath('error.code', 'plan_limit_reached');
        $this->assertSame(CardStatus::Unclaimed, Card::acrossTenants()->find($card->id)->status);
    }

    public function test_active_card_cannot_be_activated_again(): void
    {
        $owner = $this->makeUser();
        $card = $this->makeCard(CardStatus::Active, $owner);
        $this->actingAs($this->makeUser('lain@example.com'))->activate($card->card_token, self::ACTIVATION_CODE)->assertNotFound();
    }

    public function test_card_can_be_added_from_the_app_with_short_code_and_activation_code(): void
    {
        $buyer = $this->makeUser('buyer@example.com', 'Pembeli');
        $friend = $this->makeUser('teman@example.com', 'Teman');
        $card = $this->makeCard(CardStatus::Unclaimed, buyer: $buyer);
        $printed = 'TC·'.$card->formattedShortCode();

        $this->actingAs($friend)->postJson($this->host('app', '/api/cards/claim'), ['short_code' => 'ZZZZ-ZZZZ', 'code' => self::ACTIVATION_CODE])
            ->assertNotFound()->assertJsonPath('error.code', 'card_not_found');
        $this->actingAs($friend)->postJson($this->host('app', '/api/cards/claim'), ['short_code' => strtolower($printed), 'code' => '7k2m-9qxp'])
            ->assertOk()->assertJsonPath('data.next', "/cards/{$card->card_token}/setup");

        $this->assertSame(CardStatus::Active, $card->fresh()->status);
        $this->assertSame($friend->id, $card->fresh()->holder_user_id);
    }

    public function test_buyer_can_claim_by_short_code_without_activation_code(): void
    {
        $buyer = $this->makeUser();
        $card = $this->makeCard(CardStatus::Unclaimed, buyer: $buyer);
        $this->actingAs($buyer)->postJson($this->host('app', '/api/cards/claim'), ['short_code' => $card->short_code])
            ->assertOk()->assertJsonPath('data.card.status', 'active');
    }
}
