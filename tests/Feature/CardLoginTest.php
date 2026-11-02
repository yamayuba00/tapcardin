<?php

namespace Tests\Feature;

use App\Enums\CardStatus;
use App\Models\Card;
use App\Models\CardLoginKey;
use App\Models\User;
use App\Support\Hasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Masuk dengan kartu NFC: token + UID chip (dibaca Web NFC) + PIN 6 digit milik pemegang. */
class CardLoginTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const UID = '04A1B2C3D4E5F6';

    private function boundCard(User $holder, CardStatus $status = CardStatus::Active): Card
    {
        $card = $this->makeCard($status, holder: $holder);
        $card->forceFill(['chip_uid_hash' => Hasher::uid(self::UID)])->save();

        return $card;
    }

    private function withPin(Card $card, User $user, string $pin = '482915'): CardLoginKey
    {
        return CardLoginKey::create(['card_id' => $card->id, 'user_id' => $user->id, 'pin_hash' => Hash::make($pin)]);
    }

    private function cardLogin(Card $card, string $pin = '482915', string $uid = '04:a1:b2:c3:d4:e5:f6', bool $remember = false)
    {
        return $this->postJson($this->host('app', '/api/auth/card'), [
            'token' => $card->card_token, 'uid' => $uid, 'pin' => $pin, 'remember' => $remember, 'altcha' => $this->altcha(),
        ]);
    }

    public function test_card_with_matching_uid_and_pin_logs_holder_in_for_seven_days(): void
    {
        $user = $this->makeUser();
        $card = $this->boundCard($user);
        $this->withPin($card, $user);

        $res = $this->cardLogin($card, remember: true)->assertOk()->assertJsonPath('data.user.email', 'rina@example.com');
        $this->assertAuthenticatedAs($user);
        $cookie = collect($res->headers->getCookies())->first(fn ($c) => str_starts_with($c->getName(), 'remember_web_'));
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $cookie->getExpiresTime(), 120);
        $this->assertNotNull(CardLoginKey::first()->last_used_at);
    }

    public function test_public_token_without_the_chip_uid_is_rejected_without_touching_the_pin_counter(): void
    {
        $user = $this->makeUser();
        $card = $this->boundCard($user);
        $this->withPin($card, $user);

        // token tercetak di QR — tanpa UID chip yang benar, PIN bahkan tidak diperiksa
        for ($i = 0; $i < 6; $i++) {
            $this->cardLogin($card, uid: '04FFFFFFFFFFFF')->assertStatus(422)->assertJsonPath('error.message', 'Kartu atau PIN salah.');
        }
        $this->assertSame(0, CardLoginKey::first()->failed_attempts);
        $this->assertNull(CardLoginKey::first()->locked_until);
        $this->assertGuest();
    }

    public function test_wrong_pin_locks_after_five_attempts(): void
    {
        $user = $this->makeUser();
        $card = $this->boundCard($user);
        $this->withPin($card, $user);

        for ($i = 0; $i < 5; $i++) {
            $this->cardLogin($card, pin: '000111')->assertStatus(422)->assertJsonPath('error.message', 'Kartu atau PIN salah.');
        }
        $this->cardLogin($card)->assertStatus(423); // PIN benar pun ditolak selama terkunci
        $this->assertGuest();

        $this->travel(16)->minutes();
        $this->cardLogin($card)->assertOk();
    }

    public function test_cards_without_key_lost_cards_and_new_holders_cannot_log_in(): void
    {
        $user = $this->makeUser();
        $card = $this->boundCard($user);
        $this->cardLogin($card)->assertStatus(422); // belum mengatur PIN

        $key = $this->withPin($card, $user);
        $card->forceFill(['status' => CardStatus::Lost, 'lost_at' => now()])->save();
        $this->cardLogin($card)->assertStatus(422);

        $other = $this->makeUser('lain@example.com', 'Orang Lain');
        $card->forceFill(['status' => CardStatus::Active, 'holder_user_id' => $other->id, 'tenant_id' => $other->personal_tenant_id, 'profile_id' => $this->profileOf($other)->id])->save();
        $this->cardLogin($card)->assertStatus(422); // kunci milik pemegang lama gugur
        $this->assertGuest();
        $this->assertModelExists($key);
    }

    public function test_card_login_requires_altcha_and_valid_input(): void
    {
        $user = $this->makeUser();
        $card = $this->boundCard($user);
        $this->withPin($card, $user);
        $url = $this->host('app', '/api/auth/card');

        $this->postJson($url, ['token' => $card->card_token, 'uid' => self::UID, 'pin' => '482915', 'altcha' => 'palsu'])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['altcha']]]);
        $this->postJson($url, ['token' => $card->card_token, 'uid' => self::UID, 'pin' => '12ab', 'altcha' => $this->altcha()])->assertStatus(422)->assertJsonPath('error.fields.pin.0', 'PIN terdiri dari 6 angka.');
        $this->postJson($url, ['token' => 'bukan-token', 'uid' => self::UID, 'pin' => '482915', 'altcha' => $this->altcha()])->assertStatus(422);
        $this->assertGuest();
    }

    public function test_holder_sets_changes_and_removes_pin_from_card_detail(): void
    {
        $user = $this->makeUser();
        $card = $this->boundCard($user);
        $url = $this->host('app', "/api/cards/{$card->card_token}/login-key");

        $this->actingAs($user)->getJson($this->host('app', "/api/cards/{$card->card_token}"))
            ->assertOk()->assertJsonPath('data.login_key.available', true)->assertJsonPath('data.login_key.enabled', false);

        $this->putJson($url, ['pin' => '123456', 'pin_confirmation' => '123456'])->assertStatus(422)->assertJsonPath('error.fields.pin.0', fn ($m) => str_contains($m, 'mudah ditebak'));
        $this->putJson($url, ['pin' => '777777', 'pin_confirmation' => '777777'])->assertStatus(422);
        $this->putJson($url, ['pin' => '482915', 'pin_confirmation' => '482916'])->assertStatus(422)->assertJsonPath('error.fields.pin.0', 'Konfirmasi PIN tidak sama.');
        $this->putJson($url, ['pin' => '482915', 'pin_confirmation' => '482915'])->assertOk()->assertJsonPath('data.enabled', true);
        $this->assertTrue(Hash::check('482915', CardLoginKey::first()->pin_hash));
        $this->assertDatabaseHas('audit_logs', ['action' => 'card.login_key.set']);

        $this->putJson($url, ['pin' => '590284', 'pin_confirmation' => '590284'])->assertOk();
        $this->assertSame(1, CardLoginKey::count());
        $this->assertTrue(Hash::check('590284', CardLoginKey::first()->pin_hash));

        $this->deleteJson($url)->assertOk()->assertJsonPath('data.enabled', false);
        $this->assertSame(0, CardLoginKey::count());
    }

    public function test_only_the_holder_of_a_bound_physical_card_can_set_a_pin(): void
    {
        $user = $this->makeUser();
        $unbound = $this->makeCard(CardStatus::Active, holder: $user); // chip belum terdaftar
        $this->actingAs($user)->putJson($this->host('app', "/api/cards/{$unbound->card_token}/login-key"), ['pin' => '482915', 'pin_confirmation' => '482915'])
            ->assertStatus(422);

        $card = $this->boundCard($this->makeUser('lain@example.com', 'Orang Lain'));
        $this->actingAs($user)->putJson($this->host('app', "/api/cards/{$card->card_token}/login-key"), ['pin' => '482915', 'pin_confirmation' => '482915'])
            ->assertNotFound();
        $this->assertSame(0, CardLoginKey::count());
    }
}
