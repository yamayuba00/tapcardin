<?php

namespace Tests\Feature;

use App\Enums\CardStatus;
use App\Models\Connection;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/** AFD F-08 / BSD §8.4 — Tukar Kontak → lead di workspace pemilik kartu. */
class ExchangeContactTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function payload(array $extra = []): array
    {
        return [
            'name' => 'Budi Hartono', 'whatsapp' => '0812 3456 7890', 'email' => null, 'company' => 'PT Contoh',
            'note' => 'Ketemu di booth', 'consent' => true, 'website' => '', 't' => Crypt::encryptString((string) (time() - 10)),
            ...$extra,
        ];
    }

    private function connections()
    {
        return Connection::withoutGlobalScope(TenantScope::class);
    }

    public function test_exchange_creates_lead_with_consent_and_notifies_holder(): void
    {
        $rina = $this->makeUser();
        $card = $this->makeCard(CardStatus::Active, $rina);

        $this->postJson($this->host('tap', "/api/c/{$card->card_token}/connect"), $this->payload())
            ->assertCreated()->assertExactJson(['data' => ['status' => 'sent']]);

        $c = $this->connections()->firstOrFail();
        $this->assertSame($rina->personal_tenant_id, $c->tenant_id);
        $this->assertSame('+6281234567890', $c->phone_e164);
        $this->assertSame('exchange_form', $c->source->value);
        $this->assertSame($rina->id, $c->assignee_user_id);
        $this->assertSame($card->id, $c->card_id);
        $this->assertNotNull($c->consent_at);
        $this->assertSame('v1', $c->consent_text_version);
        $this->assertDatabaseHas('notifications', ['user_id' => $rina->id, 'type' => 'lead_new']);
        $this->assertDatabaseHas('lead_activities', ['connection_id' => $c->id, 'type' => 'created']);
    }

    public function test_same_phone_is_deduplicated_into_existing_lead(): void
    {
        $rina = $this->makeUser();
        $card = $this->makeCard(CardStatus::Active, $rina);
        $url = $this->host('tap', "/api/c/{$card->card_token}/connect");

        $this->postJson($url, $this->payload())->assertCreated();
        $this->postJson($url, $this->payload(['whatsapp' => '+62 812-3456-7890', 'email' => 'budi@contoh.id']))->assertCreated();

        $this->assertSame(1, $this->connections()->count());
        $this->assertSame('budi@contoh.id', $this->connections()->first()->email, 'data baru melengkapi lead lama');
        $this->assertDatabaseCount('lead_activities', 2);
    }

    public function test_honeypot_and_too_fast_submissions_are_silently_dropped(): void
    {
        $card = $this->makeCard(CardStatus::Active, $this->makeUser());
        $url = $this->host('tap', "/api/c/{$card->card_token}/connect");

        $this->postJson($url, $this->payload(['website' => 'http://spam']))->assertCreated();
        $this->postJson($url, $this->payload(['t' => Crypt::encryptString((string) time())]))->assertCreated();
        $this->postJson($url, $this->payload(['t' => 'tampered']))->assertCreated();

        $this->assertSame(0, $this->connections()->count());
    }

    public function test_validation_requires_contact_and_consent(): void
    {
        $card = $this->makeCard(CardStatus::Active, $this->makeUser());
        $url = $this->host('tap', "/api/c/{$card->card_token}/connect");

        $this->postJson($url, $this->payload(['whatsapp' => null]))->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->postJson($url, $this->payload(['consent' => false]))->assertStatus(422);
        $this->postJson($url, $this->payload(['whatsapp' => '12']))->assertStatus(422)->assertJsonPath('error.fields.whatsapp', 'Nomor WhatsApp belum valid.');
    }

    public function test_inactive_card_does_not_accept_exchange(): void
    {
        $card = $this->makeCard(CardStatus::Paused, $this->makeUser());
        $this->postJson($this->host('tap', "/api/c/{$card->card_token}/connect"), $this->payload())->assertNotFound();
    }

    public function test_free_plan_limit_does_not_break_visitor(): void
    {
        $rina = $this->makeUser();
        $rina->personalTenant->update(['limit_overrides' => ['connections' => 0]]);
        $card = $this->makeCard(CardStatus::Active, $rina);
        $this->postJson($this->host('tap', "/api/c/{$card->card_token}/connect"), $this->payload())->assertCreated();
        $this->assertSame(0, $this->connections()->count());
    }
}
