<?php

namespace Tests\Feature;

use App\Enums\TenantType;
use App\Models\Card;
use App\Models\Connection;
use App\Models\LeadStage;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Penugasan dalam cakupan tim: Manager menugaskan kartu & lead hanya ke anggota tim yang dikelolanya. */
class TeamAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Tenant $org;

    private User $owner;

    private User $manager;

    private User $inTeam;

    private User $outside;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->makeUser('owner@majujaya.id', 'Owner MJ');
        $this->org = Tenant::create([
            'type' => TenantType::Company, 'name' => 'Maju Jaya', 'slug' => 'majujaya', 'owner_user_id' => $this->owner->id,
            'status' => 'active', 'plan_id' => Plan::where('key', 'business')->value('id'), 'settings' => Tenant::DEFAULT_SETTINGS,
        ]);
        $this->manager = $this->makeUser('dewi@majujaya.id', 'Dewi Manager');
        $this->inTeam = $this->makeUser('budi@majujaya.id', 'Budi Tim');
        $this->outside = $this->makeUser('rina@majujaya.id', 'Rina Luar');
        $this->inOrg(function () {
            foreach (LeadStage::DEFAULTS as $i => $s) {
                LeadStage::create([...$s, 'sort' => $i]);
            }
            foreach ([[$this->owner, 'owner'], [$this->manager, 'manager'], [$this->inTeam, 'member'], [$this->outside, 'member']] as [$u, $role]) {
                TenantMember::create(['user_id' => $u->id, 'role_id' => Role::system($role)->id, 'status' => 'active']);
            }
            $team = Team::create(['name' => 'Sales Jakarta']);
            TeamMember::create(['team_id' => $team->id, 'user_id' => $this->manager->id, 'is_manager' => true]);
            TeamMember::create(['team_id' => $team->id, 'user_id' => $this->inTeam->id, 'is_manager' => false]);
        });
    }

    private function inOrg(callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($this->org, $fn);
    }

    private function api(string $method, string $path, array $data = [])
    {
        return $this->json($method, $this->host('majujaya', "/api$path"), $data);
    }

    private function card(?User $assigned = null): Card
    {
        $card = $this->makeCard();
        $card->forceFill(['tenant_id' => $this->org->id, 'assigned_user_id' => $assigned?->id])->save();

        return $card;
    }

    public function test_manager_assigns_cards_only_within_team(): void
    {
        $pool = $this->card();
        $outsiders = $this->card($this->outside);
        $this->actingAs($this->manager);

        $names = collect($this->api('GET', '/company/cards/assignees')->assertOk()->json('data'))->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['Dewi Manager', 'Budi Tim'], $names);
        $tokens = collect($this->api('GET', '/company/cards')->assertOk()->json('data'))->pluck('token')->all();
        $this->assertContains($pool->card_token, $tokens, 'stok perusahaan terlihat');
        $this->assertNotContains($outsiders->card_token, $tokens, 'kartu anggota tim lain tidak terlihat');

        $this->api('PUT', "/company/cards/{$pool->card_token}/assign", ['user' => $this->outside->public_id])->assertForbidden()->assertJsonPath('error.code', 'outside_team');
        $this->api('PUT', "/company/cards/{$pool->card_token}/assign", ['user' => $this->inTeam->public_id])->assertOk()->assertJsonPath('data.assigned.name', 'Budi Tim');
        $this->api('DELETE', "/company/cards/{$outsiders->card_token}/assign")->assertForbidden();
        $this->api('DELETE', "/company/cards/{$pool->card_token}/assign")->assertOk();
        $this->api('GET', '/company/members')->assertForbidden(); // kelola karyawan tetap owner/admin

        $this->actingAs($this->owner);
        $this->assertCount(4, $this->api('GET', '/company/cards/assignees')->assertOk()->json('data'));
        $this->api('PUT', "/company/cards/{$pool->card_token}/assign", ['user' => $this->outside->public_id])->assertOk();
    }

    public function test_lead_assignment_bulk_scope_notify_and_activity(): void
    {
        $this->actingAs($this->owner);
        $a = $this->api('POST', '/leads', ['name' => 'Lead A', 'email' => 'a@contoh.com'])->assertCreated()->json('data.id');
        $b = $this->api('POST', '/leads', ['name' => 'Lead B', 'email' => 'b@contoh.com'])->assertCreated()->json('data.id');

        // massal ke anggota tim → lead pindah ke tim itu, penanggung jawab diberi tahu
        $this->api('PUT', '/leads/assign', ['ids' => [$a, $b], 'user' => $this->inTeam->public_id])->assertOk()->assertJsonPath('data.updated', 2);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->inTeam->id, 'type' => 'lead_assigned']);
        $leads = Connection::withoutGlobalScope(TenantScope::class)->whereIn('public_id', [$a, $b])->get();
        $this->assertSame([$this->inTeam->id], $leads->pluck('assignee_user_id')->unique()->values()->all());
        $this->assertNotNull($leads->first()->team_id);
        $this->api('GET', "/leads/$a")->assertOk()->assertJsonFragment(['type' => 'assigned']);
        $this->api('PUT', '/leads/assign', ['ids' => [$a], 'user' => $this->inTeam->public_id])->assertOk()->assertJsonPath('data.updated', 0);

        // Manager: hanya ke anggota timnya; anggota biasa tidak boleh menugaskan
        $this->actingAs($this->manager);
        $this->assertCount(2, $this->api('GET', '/leads/assignees')->assertOk()->json('data'));
        $this->api('PUT', '/leads/assign', ['ids' => [$a], 'user' => $this->outside->public_id])->assertForbidden()->assertJsonPath('error.code', 'outside_team');
        $this->api('PUT', '/leads/assign', ['ids' => [$a], 'user' => $this->manager->public_id])->assertOk()->assertJsonPath('data.updated', 1);
        $this->api('PUT', '/leads/assign', ['ids' => [$a], 'user' => null])->assertOk(); // lepas penanggung jawab
        $this->actingAs($this->outside);
        $this->api('PUT', '/leads/assign', ['ids' => [$b], 'user' => $this->outside->public_id])->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['action' => 'lead.assigned']);
    }
}
