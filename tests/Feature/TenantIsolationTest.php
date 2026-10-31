<?php

namespace Tests\Feature;

use App\Actions\Leads\CreateConnection;
use App\Enums\CardStatus;
use App\Enums\LeadSource;
use App\Models\Card;
use App\Models\Connection;
use App\Models\Profile;
use App\Tenancy\TenantNotResolved;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** PRD PV-06 / TRD §11 — endpoint workspace dipanggil dengan ID milik tenant lain harus 403/404. */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_global_scope_refuses_queries_without_tenant_context(): void
    {
        $this->expectException(TenantNotResolved::class);
        Card::count();
    }

    public function test_cross_tenant_ids_are_not_reachable(): void
    {
        $a = $this->makeUser('a@example.com', 'Ani');
        $b = $this->makeUser('b@example.com', 'Bima');
        $cardA = $this->makeCard(CardStatus::Active, $a);
        $profileA = $this->profileOf($a);
        $leadA = $this->inTenantOf($a, fn () => app(CreateConnection::class)
            ->handle(['name' => 'Rahasia A', 'phone' => '081111111111'], LeadSource::Manual, $a->id)['connection']);

        $this->actingAs($b);
        $routes = [
            ['GET', "/api/cards/{$cardA->card_token}"],
            ['PATCH', "/api/cards/{$cardA->card_token}"],
            ['GET', "/api/profiles/{$profileA->public_id}"],
            ['PATCH', "/api/profiles/{$profileA->public_id}"],
            ['PUT', "/api/profiles/{$profileA->public_id}/fields"],
            ['GET', "/api/profiles/{$profileA->public_id}/preview"],
            ['GET', "/api/leads/{$leadA->public_id}"],
            ['PATCH', "/api/leads/{$leadA->public_id}"],
            ['DELETE', "/api/leads/{$leadA->public_id}"],
            ['POST', "/api/leads/{$leadA->public_id}/activities"],
        ];
        foreach ($routes as [$method, $path]) {
            $status = $this->json($method, $this->host('app', $path), ['label' => 'x', 'fields' => [], 'type' => 'note', 'body' => 'x'])->status();
            $this->assertContains($status, [403, 404], "$method $path bocor ($status)");
        }

        $this->getJson($this->host('app', '/api/cards'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.form', 'digital')
            ->assertJsonMissing(['token' => $cardA->card_token]); // hanya kartu digital milik sendiri
        $this->getJson($this->host('app', '/api/leads'))->assertOk()->assertJsonCount(0, 'data');
        $this->get($this->host('app', '/api/leads/export.csv'))->assertOk()->assertDontSee('Rahasia A');
        $profiles = $this->getJson($this->host('app', '/api/profiles'))->json('data');
        $this->assertSame([Profile::acrossTenants()->where('user_id', $b->id)->value('public_id')], array_column($profiles, 'id'));
    }

    public function test_company_subdomain_requires_membership(): void
    {
        $outsider = $this->makeUser('luar@example.com');
        $this->actingAs($outsider)->getJson($this->host('majujaya', '/api/dashboard'))->assertNotFound();
    }

    public function test_tenant_scope_filters_to_current_tenant(): void
    {
        $a = $this->makeUser('a@example.com');
        $b = $this->makeUser('b@example.com');
        $this->inTenantOf($a, fn () => app(CreateConnection::class)->handle(['name' => 'Lead A'], LeadSource::Manual, $a->id));
        $this->inTenantOf($b, fn () => app(CreateConnection::class)->handle(['name' => 'Lead B'], LeadSource::Manual, $b->id));

        $this->assertSame(['Lead A'], $this->inTenantOf($a, fn () => Connection::pluck('name')->all()));
        $this->assertSame(['Lead B'], $this->inTenantOf($b, fn () => Connection::pluck('name')->all()));
    }
}
