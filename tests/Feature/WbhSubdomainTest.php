<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** wbh.{base}: webhook masuk gateway pembayaran + dokumentasi publik webhook keluar (tanpa sesi), slug wbh dicadangkan. */
class WbhSubdomainTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_docs_spa_and_public_catalog_without_session(): void
    {
        $res = $this->get($this->host('wbh', '/'))->assertOk()->assertSee('id="app"', false);
        $this->assertEmpty(array_filter($res->headers->getCookies(), fn ($c) => $c->getName() === config('session.cookie')));

        $events = collect($this->getJson($this->host('wbh', '/api/catalog'))->assertOk()->json('data.events'))->pluck('key');
        foreach (['lead.created', 'lead.assigned', 'event.checkin', 'tap.recorded'] as $key) {
            $this->assertContains($key, $events);
        }
        $this->getJson($this->host('wbh', '/api/catalog'))->assertJsonPath('data.events.0.sample.workspace.slug', 'majujaya')
            ->assertJsonStructure(['data' => ['ping', 'retry', 'disable_after']]);
    }

    public function test_payment_webhook_is_served_on_wbh_and_old_url_still_works(): void
    {
        foreach (['http://wbh.localhost/payment', 'http://localhost/webhooks/payment'] as $url) {
            $status = $this->postJson($url, ['event' => 'payment.unknown'])->getStatusCode();
            $this->assertNotContains($status, [404, 405, 419], "rute webhook gateway hidup tanpa CSRF: $url");
        }
    }

    public function test_wbh_is_not_a_workspace_and_cannot_be_taken_as_slug(): void
    {
        $this->assertContains('wbh', config('tapcard.reserved_slugs'));
        $this->actingAs($this->makeUser());
        $this->getJson($this->host('wbh', '/api/me'))->assertNotFound();
    }
}
