<?php

namespace Tests\Feature;

use App\Enums\CardStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Gerbang X-Tap-Key: semua /api kecuali endpoint tap (NFC/QR) dan SSE notifikasi. */
class ApiKeyGateTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_api_without_key_is_forbidden(): void
    {
        $this->withoutHeader('X-Tap-Key');
        $this->getJson($this->host('status', '/api/status'))->assertForbidden()->assertJsonPath('error.code', 'api_key_required');
    }

    public function test_api_with_wrong_key_is_forbidden(): void
    {
        $this->withHeader('X-Tap-Key', 'salah')->getJson($this->host('status', '/api/status'))->assertForbidden();
    }

    public function test_api_with_correct_key_passes(): void
    {
        $this->getJson($this->host('status', '/api/status'))->assertOk();
    }

    public function test_tap_endpoints_stay_open_without_key(): void
    {
        $this->withoutHeader('X-Tap-Key');
        $card = $this->makeCard(CardStatus::Active, $this->makeUser());
        $this->getJson($this->host('tap', "/api/c/{$card->card_token}"))->assertOk();
    }

    public function test_production_without_configured_key_fails_closed(): void
    {
        $this->withoutHeader('X-Tap-Key');
        config(['services.tap_api.key' => null]);
        $this->app['env'] = 'production';
        $this->getJson($this->host('status', '/api/status'))->assertStatus(503)->assertJsonPath('error.code', 'api_key_not_configured');
    }

    public function test_local_without_configured_key_stays_usable(): void
    {
        $this->withoutHeader('X-Tap-Key');
        config(['services.tap_api.key' => null]);
        $this->app['env'] = 'local';
        $this->getJson($this->host('status', '/api/status'))->assertOk();
    }

    public function test_spa_shell_carries_key_except_on_tap_host(): void
    {
        $this->get($this->host('app', '/login'))->assertOk()->assertSee('test-api-key', false);
        $card = $this->makeCard(CardStatus::Active, $this->makeUser());
        $this->get($this->host('tap', "/c/{$card->card_token}"))->assertOk()->assertDontSee('test-api-key', false);
    }
}
