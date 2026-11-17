<?php

namespace Tests\Feature;

use App\Enums\CardStatus;
use App\Enums\LeadSource;
use App\Enums\TenantType;
use App\Http\Controllers\Auth\GoogleController;
use App\Models\Card;
use App\Models\Connection;
use App\Models\LeadStage;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

/** Area perusahaan di subdomain: login bermerek + handoff Google, karyawan, kartu karyawan, rekap lead. */
class CompanyAreaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Tenant $org;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->makeUser('owner@majujaya.id', 'Owner MJ');
        $this->org = Tenant::create([
            'type' => TenantType::Company, 'name' => 'Maju Jaya', 'slug' => 'majujaya', 'owner_user_id' => $this->owner->id,
            'status' => 'active', 'plan_id' => Plan::where('key', 'business')->value('id'), 'settings' => Tenant::DEFAULT_SETTINGS,
        ]);
        $this->inOrg(function () {
            foreach (LeadStage::DEFAULTS as $i => $s) {
                LeadStage::create([...$s, 'sort' => $i]);
            }
        });
        $this->join($this->owner, 'owner');
    }

    private function inOrg(callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($this->org, $fn);
    }

    private function join(User $user, string $role): void
    {
        $this->inOrg(fn () => TenantMember::create(['user_id' => $user->id, 'role_id' => Role::system($role)->id, 'status' => 'active']));
    }

    private function api(string $method, string $path, array $data = [])
    {
        return $this->json($method, $this->host('majujaya', "/api$path"), $data);
    }

    private function poolCard(): Card
    {
        $card = $this->makeCard();
        $card->forceFill(['tenant_id' => $this->org->id])->save();

        return $card;
    }

    public function test_login_page_is_branded_on_company_host_only(): void
    {
        $this->get($this->host('majujaya', '/login'))->assertOk()->assertViewHas('config', fn ($c) => $c['tenant'] === ['name' => 'Maju Jaya', 'logo' => null]);
        $this->get($this->host('app', '/login'))->assertOk()->assertViewHas('config', fn ($c) => $c['tenant'] === null);
    }

    public function test_google_login_from_company_host_hands_off_with_single_use_ticket(): void
    {
        config(['services.google.client_id' => 'cid', 'services.google.client_secret' => 'sec', 'services.google.redirect' => 'http://app.localhost/auth/google/callback']);
        $res = $this->get($this->host('majujaya', '/auth/google/redirect?redirect=/members'));
        $this->assertStringStartsWith('http://app.localhost/auth/google/redirect?', $res->headers->get('Location'));
        $this->assertStringContainsString('origin=majujaya.localhost', $res->headers->get('Location'));

        // asal tak dikenal tidak disimpan (cegah open redirect)
        $this->get($this->host('app', '/auth/google/redirect?origin=evil.com'))->assertSessionMissing('auth.origin');
        $this->get($this->host('app', '/auth/google/redirect?origin=majujaya.localhost&redirect=/members'))->assertSessionHas('auth.origin', 'majujaya.localhost');

        Socialite::shouldReceive('driver')->with('google')->andReturn(Mockery::mock(['user' => (new SocialiteUser)
            ->setRaw(['email_verified' => true])->map(['id' => 'g-1', 'email' => 'owner@majujaya.id', 'name' => 'Owner MJ'])]));
        $location = $this->withSession(['auth.origin' => 'majujaya.localhost', 'auth.redirect' => '/members'])
            ->get($this->host('app', '/auth/google/callback'))->headers->get('Location');
        $this->assertStringStartsWith('http://majujaya.localhost/auth/handoff?ticket=', $location);
        $ticket = substr($location, strpos($location, 'ticket=') + 7);

        $this->app['auth']->guard('web')->logout();
        $this->get($this->host('majujaya', "/auth/handoff?ticket=$ticket"))->assertRedirect('/members');
        $this->assertAuthenticatedAs($this->owner);

        $this->app['auth']->guard('web')->logout();
        $this->get($this->host('majujaya', "/auth/handoff?ticket=$ticket"))->assertRedirect('/login?error=handoff'); // sekali pakai
        $this->assertGuest();
    }

    public function test_handoff_ticket_rejects_other_host_and_expires(): void
    {
        Tenant::create(['type' => TenantType::Company, 'name' => 'Lain', 'slug' => 'lain', 'owner_user_id' => $this->owner->id, 'status' => 'active',
            'plan_id' => Plan::where('key', 'business')->value('id'), 'settings' => Tenant::DEFAULT_SETTINGS]);
        Cache::put(GoogleController::ticketKey('t-host'), ['user' => $this->owner->id, 'host' => 'majujaya.localhost', 'path' => '/dashboard'], 60);
        $this->get($this->host('lain', '/auth/handoff?ticket=t-host'))->assertRedirect('/login?error=handoff');
        $this->assertGuest();

        Cache::put(GoogleController::ticketKey('t-old'), ['user' => $this->owner->id, 'host' => 'majujaya.localhost', 'path' => '/dashboard'], GoogleController::TICKET_TTL);
        $this->travel(GoogleController::TICKET_TTL + 1)->seconds();
        $this->get($this->host('majujaya', '/auth/handoff?ticket=t-old'))->assertRedirect('/login?error=handoff');
        $this->assertGuest();
    }

    public function test_non_member_gets_clear_message(): void
    {
        $this->actingAs($this->makeUser('tamu@example.com', 'Tamu'));
        $this->api('GET', '/me')->assertForbidden()->assertJsonPath('error.code', 'not_member')
            ->assertJsonPath('error.message', 'Anda belum terdaftar sebagai anggota Maju Jaya. Minta owner menambahkan email Anda.');
    }

    public function test_owner_adds_registered_accounts_only(): void
    {
        $this->makeStaff('support', 'staf@tapcard.test');
        $this->makeUser('sari@example.com', 'Sari');
        $this->actingAs($this->owner);

        $this->api('POST', '/company/members', ['email' => 'belum@example.com', 'role' => 'member'])->assertJsonPath('error.code', 'member_not_found');
        $this->api('POST', '/company/members', ['email' => 'staf@tapcard.test', 'role' => 'member'])->assertJsonPath('error.code', 'member_not_found');
        $this->api('POST', '/company/members', ['email' => 'owner@majujaya.id', 'role' => 'admin'])->assertJsonPath('error.code', 'member_exists');
        $this->api('POST', '/company/members', ['email' => 'owner', 'role' => 'member'])->assertUnprocessable();
        $this->api('POST', '/company/members', ['email' => 'Sari@Example.com', 'role' => 'manager'])->assertCreated()
            ->assertJsonPath('data.email', 'sari@example.com')->assertJsonPath('data.role', 'manager');
        $this->api('POST', '/company/members', ['email' => 'sari@example.com', 'role' => 'member'])->assertJsonPath('error.code', 'member_exists');

        $this->actingAs(User::where('email', 'sari@example.com')->first());
        $this->api('GET', '/me')->assertOk();
    }

    public function test_role_change_deactivate_and_owner_is_protected(): void
    {
        $sari = $this->makeUser('sari@example.com', 'Sari');
        $this->join($sari, 'member');
        $card = $this->makeCard(CardStatus::Active, $sari);
        $card->forceFill(['tenant_id' => $this->org->id])->save();
        $this->actingAs($this->owner);

        $this->api('PATCH', "/company/members/{$this->owner->public_id}", ['role' => 'member'])->assertJsonPath('error.code', 'owner_protected');
        $this->api('POST', "/company/members/{$this->owner->public_id}/deactivate")->assertJsonPath('error.code', 'owner_protected');
        $this->api('PATCH', "/company/members/{$sari->public_id}", ['role' => 'owner'])->assertUnprocessable();
        $this->api('PATCH', "/company/members/{$sari->public_id}", ['role' => 'admin'])->assertOk()->assertJsonPath('data.role', 'admin');

        $this->api('POST', "/company/members/{$sari->public_id}/deactivate")->assertOk()->assertJsonPath('data.status', 'removed');
        $card->refresh();
        $this->assertSame(CardStatus::Unclaimed, $card->status);
        $this->assertNull($card->holder_user_id);
        $this->assertNull($card->profile_id);

        $this->actingAs($sari);
        $this->api('GET', '/me')->assertJsonPath('error.code', 'not_member');

        $this->actingAs($this->owner);
        $this->api('POST', "/company/members/{$sari->public_id}/reactivate")->assertOk()->assertJsonPath('data.status', 'active');
        $this->api('GET', '/company/members')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.roles.0.key', 'admin');
    }

    public function test_assign_and_unassign_company_cards(): void
    {
        $sari = $this->makeUser('sari@example.com', 'Sari');
        $this->join($sari, 'member');
        $outsider = $this->makeUser('luar@example.com', 'Luar');
        $card = $this->poolCard();
        $this->actingAs($this->owner);

        $this->api('GET', '/company/cards?filter=pool')->assertOk()->assertJsonPath('data.0.token', $card->card_token);
        $this->api('PUT', "/company/cards/{$card->card_token}/assign", ['user' => $outsider->public_id])->assertJsonPath('error.code', 'not_member');
        $this->api('PUT', "/company/cards/{$card->card_token}/assign", ['user' => $sari->public_id])->assertOk()->assertJsonPath('data.assigned.email', 'sari@example.com');
        $this->assertSame($sari->id, $card->fresh()->assigned_user_id);

        $this->api('DELETE', "/company/cards/{$card->card_token}/assign")->assertOk()->assertJsonPath('data.assigned', null);
        $this->assertNull($card->fresh()->assigned_user_id);
        $this->api('GET', '/company/summary')->assertOk()->assertJsonPath('data.cards_pool', 1)->assertJsonPath('data.cards_max', 500)
            ->assertJsonPath('data.members', 2);
    }

    public function test_lead_recap_counts_per_member(): void
    {
        $sari = $this->makeUser('sari@example.com', 'Sari');
        $this->join($sari, 'member');
        $this->inOrg(function () use ($sari) {
            $stage = LeadStage::where('is_default', true)->value('id');
            foreach (['A', 'B'] as $n) {
                Connection::create(['source' => LeadSource::Manual, 'captured_by_user_id' => $sari->id, 'assignee_user_id' => $sari->id, 'stage_id' => $stage, 'name' => $n]);
            }
            $old = Connection::create(['source' => LeadSource::Manual, 'captured_by_user_id' => $sari->id, 'assignee_user_id' => $sari->id, 'stage_id' => $stage, 'name' => 'Lama']);
            $old->forceFill(['created_at' => now()->subDays(40)])->save();
        });
        $this->actingAs($this->owner);

        $rows = collect($this->api('GET', '/company/leads')->assertOk()->json('data'))->keyBy('email');
        $this->assertSame(2, $rows['sari@example.com']['leads_30']);
        $this->assertSame(3, $rows['sari@example.com']['leads_total']);
        $this->assertSame(0, $rows['owner@majujaya.id']['leads_total']);
    }

    public function test_members_cannot_use_management_and_personal_workspace_has_no_company_area(): void
    {
        $sari = $this->makeUser('sari@example.com', 'Sari');
        $this->join($sari, 'member');
        $this->actingAs($sari);
        foreach (['/company/summary', '/company/members', '/company/cards', '/company/leads'] as $path) {
            $this->api('GET', $path)->assertForbidden();
        }
        $this->api('POST', '/company/members', ['email' => 'owner@majujaya.id', 'role' => 'member'])->assertForbidden();

        $this->json('GET', $this->host('app', '/api/company/summary'))->assertNotFound()->assertJsonPath('error.code', 'company_only');
    }
}
