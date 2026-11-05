<?php

namespace Tests\Feature;

use App\Enums\CardStatus;
use App\Enums\TenantStatus;
use App\Exceptions\ApiError;
use App\Models\Card;
use App\Models\Notification;
use App\Models\ProfileField;
use App\Models\Tap;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Tests\TestCase;

/** PRD §8 — state resolver per status kartu & workspace; TRD §6.2. SPA memanggil GET /api/c/{token}. */
class ResolverTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function resolve(string $token, string $query = '', array $headers = [])
    {
        return $this->getJson($this->host('tap', "/api/c/$token$query"), $headers);
    }

    public function test_chip_url_serves_spa_shell(): void
    {
        $card = $this->makeCard(CardStatus::Active, $this->makeUser());
        $this->get($this->host('tap', "/c/{$card->card_token}"))->assertOk()->assertSee('id="app"', false);
    }

    public function test_unknown_token_is_404(): void
    {
        $this->resolve('AAAAAAAAAA')->assertNotFound()->assertJsonPath('data.state', 'unknown');
    }

    public function test_active_card_returns_profile_records_tap_and_notifies_holder(): void
    {
        $rina = $this->makeUser();
        $card = $this->makeCard(CardStatus::Active, $rina);

        $res = $this->resolve($card->card_token, '?src=qr', ['CF-IPCountry' => 'ID', 'cf-ipcity' => 'Jakarta']);

        $res->assertOk()
            ->assertJsonPath('data.state', 'profile')
            ->assertJsonPath('data.profile.display_name', 'Rina Saputra')
            ->assertJsonPath('data.profile.vcard_url', "http://tap.localhost/c/{$card->card_token}/vcard")
            ->assertJsonPath('data.profile.exchange.url', "http://tap.localhost/api/c/{$card->card_token}/connect")
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $tap = Tap::withoutGlobalScope(TenantScope::class)->firstOrFail();
        $this->assertSame('qr', $tap->source->value);
        $this->assertSame('Jakarta', $tap->city);
        $this->assertSame($rina->id, $tap->holder_user_id);
        $this->assertSame(64, strlen($tap->ip_hash), 'IP disimpan sebagai hash');
        $this->assertDatabaseHas('notifications', ['user_id' => $rina->id, 'type' => 'tap']);
        $this->assertNotNull($card->fresh()->last_tap_at);
    }

    public function test_unclaimed_personal_card_shows_activation(): void
    {
        $card = $this->makeCard(CardStatus::Unclaimed);
        $this->resolve($card->card_token)->assertOk()->assertJsonPath('data.state', 'activate')
            ->assertJsonPath('data.token', $card->card_token);
        $this->assertSame(0, Tap::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_paused_lost_revoked_and_manufactured_are_neutral(): void
    {
        $rina = $this->makeUser();
        foreach ([[CardStatus::Paused, 'paused'], [CardStatus::Lost, 'lost'], [CardStatus::Manufactured, 'manufactured']] as [$status, $reason]) {
            $card = $this->makeCard($status, $rina);
            $this->resolve($card->card_token)->assertOk()
                ->assertJsonPath('data.state', 'inactive')->assertJsonPath('data.reason', $reason)
                ->assertDontSee('Rina Saputra');
        }
        $revoked = $this->makeCard(CardStatus::Unclaimed);
        $revoked->update(['status' => CardStatus::Revoked]);
        $this->resolve($revoked->card_token)->assertJsonPath('data.reason', 'revoked');
    }

    public function test_tap_on_lost_card_notifies_holder(): void
    {
        $rina = $this->makeUser();
        $card = $this->makeCard(CardStatus::Lost, $rina);
        $this->resolve($card->card_token, '', ['cf-ipcity' => 'Bandung']);
        $n = Notification::where('user_id', $rina->id)->where('type', 'lost_card_tapped')->firstOrFail();
        $this->assertSame('Bandung', $n->data['city']);
    }

    public function test_suspended_workspace_shows_neutral_state(): void
    {
        $rina = $this->makeUser();
        $card = $this->makeCard(CardStatus::Active, $rina);
        $rina->personalTenant->update(['status' => TenantStatus::Suspended]);
        $this->resolve($card->card_token)->assertJsonPath('data.reason', 'suspended')->assertDontSee('Rina Saputra');
    }

    public function test_vcard_download(): void
    {
        $rina = $this->makeUser();
        $card = $this->makeCard(CardStatus::Active, $rina);
        $this->inTenantOf($rina, fn () => ProfileField::create([
            'profile_id' => $card->profile_id, 'type' => 'whatsapp', 'label' => 'WhatsApp', 'value' => '+6281234567890', 'sort' => 1,
        ]));

        $res = $this->get($this->host('tap', "/c/{$card->card_token}/vcard"));
        $res->assertOk()->assertHeader('Content-Type', 'text/vcard; charset=utf-8');
        $this->assertSame('inline; filename="rina-saputra.vcf"', $res->headers->get('Content-Disposition'), 'dibuka langsung (iOS: layar Kontak Baru), bukan diunduh');
        $body = $res->getContent();
        $this->assertStringContainsString("FN:Rina Saputra\r\n", $body);
        $this->assertStringContainsString('TEL;TYPE=CELL,WHATSAPP:+6281234567890', $body);
        $this->assertStringContainsString('EMAIL;TYPE=INTERNET:rina@example.com', $body);
    }

    public function test_vcard_for_inactive_card_is_404(): void
    {
        $card = $this->makeCard(CardStatus::Paused, $this->makeUser());
        $this->get($this->host('tap', "/c/{$card->card_token}/vcard"))->assertNotFound();
    }

    public function test_short_code_resolves_to_token(): void
    {
        Exceptions::fake();
        $card = $this->makeCard(CardStatus::Active, $this->makeUser());
        $this->postJson($this->host('tap', '/api/shortcode'), ['code' => strtolower($card->formattedShortCode())])
            ->assertOk()->assertJsonPath('data.token', $card->card_token);
        $this->postJson($this->host('tap', '/api/shortcode'), ['code' => 'ZZZZ-ZZZZ'])->assertNotFound()->assertJsonPath('error.code', 'shortcode_unknown');
        Exceptions::assertNotReported(ApiError::class); // error bisnis tidak masuk log
    }

    public function test_link_redirect_counts_click(): void
    {
        $rina = $this->makeUser();
        $card = $this->makeCard(CardStatus::Active, $rina);
        $field = $this->inTenantOf($rina, fn () => ProfileField::create([
            'profile_id' => $card->profile_id, 'type' => 'url', 'label' => 'Website', 'value' => 'https://contoh.id', 'sort' => 1,
        ]));
        $this->get($this->host('tap', "/c/{$card->card_token}/l/{$field->public_id}"))->assertRedirect('https://contoh.id');
        $this->assertSame(1, $field->fresh()->click_count);
    }

    public function test_handle_resolves_to_active_card(): void
    {
        $rina = $this->makeUser();
        $rina->update(['handle' => 'rina']);
        $card = $this->makeCard(CardStatus::Active, $rina);
        $digital = Card::acrossTenants()->where(['holder_user_id' => $rina->id, 'form' => 'digital'])->firstOrFail();
        // kartu digital dulu (selalu ada di ponsel); bila dijeda → kartu fisik aktif
        $this->getJson($this->host('tap', '/api/handle/rina'))->assertOk()->assertJsonPath('data.token', $digital->card_token);
        $digital->update(['status' => CardStatus::Paused]);
        $this->getJson($this->host('tap', '/api/handle/rina'))->assertOk()->assertJsonPath('data.token', $card->card_token);
        $this->getJson($this->host('tap', '/api/handle/tidakada'))->assertNotFound();
    }

    public function test_qr_svg_encodes_card_url(): void
    {
        $card = $this->makeCard();
        $this->get($this->host('tap', "/c/{$card->card_token}/qr.svg"))->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    }

    public function test_chip_url_inlines_profile_meta_and_records_exactly_one_tap(): void
    {
        $card = $this->makeCard(CardStatus::Active, $this->makeUser());

        $html = $this->get($this->host('tap', "/c/{$card->card_token}?src=nfc"))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('<title>Rina Saputra', false)
            ->assertSee('og:title', false)
            ->getContent();

        $this->assertStringContainsString('"initial":{"card":{"token":"'.$card->card_token.'","state":"profile"', $html);
        $this->assertSame(1, Tap::withoutGlobalScope(TenantScope::class)->count(), 'SPA memakai data sisipan, bukan memanggil API lagi');
    }

    public function test_unknown_chip_url_is_404_shell_without_tap(): void
    {
        $this->get($this->host('tap', '/c/AAAAAAAAAA'))->assertNotFound()->assertSee('id="app"', false)->assertSee('"state":"unknown"', false);
        $this->assertSame(0, Tap::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_daily_stats_aggregation_runs_on_postgres(): void
    {
        $card = $this->makeCard(CardStatus::Active, $this->makeUser());
        $this->getJson($this->host('tap', "/api/c/{$card->card_token}?src=qr"))->assertOk();
        $this->getJson($this->host('tap', "/api/c/{$card->card_token}"))->assertOk();

        $this->artisan('tapcard:aggregate-stats')->assertSuccessful();
        $this->assertDatabaseHas('daily_card_stats', ['card_id' => $card->id, 'taps' => 2, 'qr' => 1, 'nfc' => 1, 'connections' => 0]);
    }
}
