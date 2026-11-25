<?php

namespace Tests\Feature;

use App\Actions\Leads\CreateConnection;
use App\Enums\CardStatus;
use App\Enums\LeadSource;
use App\Enums\TenantType;
use App\Models\Card;
use App\Models\LeadReminder;
use App\Models\LeadStage;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Tampilan tim: follow-up per anggota, ringkasan lead per kartu, dan penanggung jawab kartu di event. */
class TeamFollowupsEventsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Tenant $org;

    private User $owner;

    private User $budi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->makeUser('owner@majujaya.id', 'Owner MJ');
        $this->org = Tenant::create([
            'type' => TenantType::Company, 'name' => 'Maju Jaya', 'slug' => 'majujaya', 'owner_user_id' => $this->owner->id,
            'status' => 'active', 'plan_id' => Plan::where('key', 'business')->value('id'), 'settings' => Tenant::DEFAULT_SETTINGS,
        ]);
        $this->budi = $this->makeUser('budi@majujaya.id', 'Budi');
        app(CurrentTenant::class)->run($this->org, function () {
            foreach (LeadStage::DEFAULTS as $i => $stage) {
                LeadStage::create([...$stage, 'sort' => $i]);
            }
        });
        $this->join($this->owner, 'owner');
        $this->join($this->budi, 'member');
    }

    private function join(User $user, string $role): void
    {
        app(CurrentTenant::class)->run($this->org, fn () => TenantMember::create(['user_id' => $user->id, 'role_id' => Role::system($role)->id, 'status' => 'active']));
    }

    /** lead perusahaan dengan penanggung jawab & kartu sumber tertentu */
    private function lead(string $name, ?User $assignee, ?Card $card = null): int
    {
        return app(CurrentTenant::class)->run($this->org, function () use ($name, $assignee, $card) {
            $c = app(CreateConnection::class)->handle(['name' => $name, 'phone' => '0812'.random_int(10000000, 99999999)], LeadSource::Manual, $this->owner->id)['connection'];
            $c->forceFill(['assignee_user_id' => $assignee?->id, 'card_id' => $card?->id])->save();
            LeadReminder::where('connection_id', $c->id)->delete(); // pengingat bawaan dari pembuatan lead

            return $c->id;
        });
    }

    private function reminder(int $connectionId, User $by, int $hours): void
    {
        app(CurrentTenant::class)->run($this->org, fn () => LeadReminder::create([
            'connection_id' => $connectionId, 'user_id' => $by->id, 'due_at' => now()->addHours($hours), 'note' => 'tindak lanjut',
        ]));
    }

    private function companyCard(User $holder): Card
    {
        $card = $this->makeCard(CardStatus::Active, $holder);
        $card->forceFill(['tenant_id' => $this->org->id, 'form' => Card::PHYSICAL])->save();

        return $card->fresh();
    }

    private function api(string $method, string $path, array $data = [])
    {
        return $this->json($method, $this->host('majujaya', "/api$path"), $data);
    }

    public function test_owner_sees_team_followups_with_per_member_rekap_and_member_filter(): void
    {
        $sari = $this->makeUser('sari@majujaya.id', 'Sari');
        $this->join($sari, 'member');
        $this->reminder($this->lead('Lead Budi', $this->budi), $this->budi, -2);  // terlambat
        $this->reminder($this->lead('Lead Sari', $sari), $sari, 5);             // mendatang
        $this->reminder($this->lead('Lead Kosong', null), $this->owner, -1);    // belum ditugaskan

        $this->actingAs($this->owner);
        $res = $this->api('GET', '/reminders?due=overdue')->assertOk();
                $this->assertCount(2, $res->json('data'), 'owner melihat pengingat seluruh tim');
        $members = collect($res->json('meta.members'))->keyBy('name');
        $this->assertSame(1, $members['Budi']['overdue']);
        $this->assertSame(1, $members['Belum ditugaskan']['overdue']);
        $this->assertTrue(collect($res->json('meta.team'))->pluck('name')->contains('Sari'));

        $budiId = $members['Budi']['id'];
        $filtered = $this->api('GET', '/reminders?'.http_build_query(['due' => 'overdue', 'member' => $budiId]))->assertOk();
        $this->assertCount(1, $filtered->json('data'));
        $this->assertSame('Lead Budi', $filtered->json('data.0.lead.name'));
        $this->assertSame('Budi', $filtered->json('data.0.lead.assignee.name'));
    }

    public function test_member_still_sees_only_own_reminders(): void
    {
        $this->reminder($this->lead('Lead Budi', $this->budi), $this->budi, -2);
        $this->reminder($this->lead('Lead Orang Lain', null), $this->owner, -2);

        $this->actingAs($this->budi);
        $res = $this->api('GET', '/reminders?due=overdue')->assertOk();
        $this->assertCount(1, $res->json('data'), 'anggota melihat pengingatnya sendiri');
        $this->assertSame('Lead Budi', $res->json('data.0.lead.name'));
        $this->assertFalse(collect($res->json('data'))->contains('lead.name', 'Lead Orang Lain'), 'pengingat owner tidak tampil di akun anggota');
        $this->assertNull($res->json('meta.members'));
    }

    public function test_lead_dashboard_breaks_leads_down_per_card(): void
    {
        $card = $this->companyCard($this->budi);
        $this->lead('Kartu Budi 1', $this->budi, $card);
        $this->lead('Kartu Budi 2', $this->budi, $card);

        $this->actingAs($this->owner);
        $bars = $this->api('GET', '/leads/dashboard')->assertOk()->json('data.widgets.by_card.bars');
        $this->assertSame(2, $bars[0]['value']);
        $this->assertStringContainsString('Budi', $bars[0]['label']);
    }

    public function test_event_card_can_be_assigned_to_a_team_member(): void
    {
        $card = $this->companyCard($this->budi);
        $this->actingAs($this->owner);
        $event = $this->api('POST', '/events', ['name' => 'Expo UMKM', 'starts_at' => now()->subHour()->toIso8601String(), 'ends_at' => now()->addHours(5)->toIso8601String()])
            ->assertCreated()->json('data.id');
        $this->api('PUT', "/events/$event/cards", ['cards' => [$card->card_token]])->assertOk();

        $budiRef = $this->api('GET', "/events/$event")->json('data.members');
        $budiId = collect($budiRef)->firstWhere('name', 'Budi')['id'];
        $res = $this->api('PUT', "/events/$event/cards/{$card->card_token}/assignee", ['user' => $budiId])->assertOk();
        $this->assertSame('Budi', $res->json('data.cards.0.assignee.name'));

        $this->api('PUT', "/events/$event/cards/{$card->card_token}/assignee", ['user' => null])->assertOk()
            ->assertJsonPath('data.cards.0.assignee', null);
    }
}
