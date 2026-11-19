<?php

namespace Tests\Feature;

use App\Enums\CardStatus;
use App\Enums\TenantType;
use App\Models\Card;
use App\Models\LeadStage;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Check-in peserta event: panitia memindai kartu, sekali per kartu, tamu dari workspace lain, daftar hadir, CSV, batal. */
class EventCheckinTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Tenant $org;

    private User $owner;

    private User $panitia;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->makeUser('owner@majujaya.id', 'Owner MJ');
        $this->org = Tenant::create([
            'type' => TenantType::Company, 'name' => 'Maju Jaya', 'slug' => 'majujaya', 'owner_user_id' => $this->owner->id,
            'status' => 'active', 'plan_id' => Plan::where('key', 'business')->value('id'), 'settings' => Tenant::DEFAULT_SETTINGS,
        ]);
        $this->panitia = $this->makeUser('panitia@majujaya.id', 'Panitia');
        $this->member = $this->makeUser('budi@majujaya.id', 'Budi Peserta');
        app(CurrentTenant::class)->run($this->org, function () {
            foreach (LeadStage::DEFAULTS as $i => $s) {
                LeadStage::create([...$s, 'sort' => $i]);
            }
            foreach ([[$this->owner, 'owner'], [$this->panitia, 'event_staff'], [$this->member, 'member']] as [$u, $role]) {
                TenantMember::create(['user_id' => $u->id, 'role_id' => Role::system($role)->id, 'status' => 'active']);
            }
        });
    }

    private function api(string $method, string $path, array $data = [])
    {
        return $this->json($method, $this->host('majujaya', "/api$path"), $data);
    }

    private function event(): string
    {
        $this->actingAs($this->owner);

        return $this->api('POST', '/events', ['name' => 'Expo UMKM', 'starts_at' => now()->subHour()->toIso8601String(), 'ends_at' => now()->addHours(5)->toIso8601String()])
            ->assertCreated()->json('data.id');
    }

    private function orgCard(User $holder): Card
    {
        $card = $this->makeCard(CardStatus::Active, $holder);
        $card->forceFill(['tenant_id' => $this->org->id])->save();

        return $card;
    }

    public function test_panitia_checks_in_once_per_card_with_live_count_and_csv(): void
    {
        $event = $this->event();
        $card = $this->orgCard($this->member);
        $this->actingAs($this->panitia);
        $url = config('tapcard.resolver_url')."/c/{$card->card_token}?src=qr";

        $res = $this->api('POST', "/events/$event/checkins", ['value' => $url, 'method' => 'nfc'])->assertCreated();
        $res->assertJsonPath('data.status', 'checked_in')->assertJsonPath('data.checkin.name', 'Budi Peserta')
            ->assertJsonPath('data.checkin.guest', false)->assertJsonPath('data.count', 1);
        // kartu sama lewat kode pendek → sudah check-in, tidak tercatat dua kali
        $this->api('POST', "/events/$event/checkins", ['value' => $card->short_code, 'method' => 'code'])->assertOk()
            ->assertJsonPath('data.status', 'duplicate')->assertJsonPath('data.count', 1);

        $this->api('POST', "/events/$event/checkins", ['value' => 'https://contoh.com/bukan-kartu', 'method' => 'qr'])->assertStatus(422)->assertJsonPath('error.code', 'card_not_found');
        $paused = $this->orgCard($this->makeUser('jeda@majujaya.id', 'Jeda'));
        $paused->forceFill(['status' => CardStatus::Paused])->save();
        $this->api('POST', "/events/$event/checkins", ['value' => config('tapcard.resolver_url')."/c/{$paused->card_token}", 'method' => 'code'])->assertStatus(422)->assertJsonPath('error.code', 'card_inactive');

        $this->api('GET', "/events/$event/checkins")->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.by.name', 'Panitia');
        $csv = $this->get($this->host('majujaya', "/api/events/$event/checkins.csv"))->assertOk()->streamedContent();
        $this->assertStringContainsString('Budi Peserta', $csv);
    }

    public function test_card_from_another_workspace_checks_in_as_guest_with_public_name_only(): void
    {
        $event = $this->event();
        $outsider = $this->makeUser('tamu@lain.id', 'Tamu Luar');
        $card = $this->makeCard(CardStatus::Active, $outsider); // workspace pribadinya, bukan Maju Jaya
        $this->actingAs($this->panitia);

        $res = $this->api('POST', "/events/$event/checkins", ['value' => config('tapcard.resolver_url')."/c/{$card->card_token}", 'method' => 'code']);
        $res->assertCreated();
        $res->assertJsonPath('data.checkin.guest', true);
        $this->assertDatabaseHas('event_checkins', ['card_id' => $card->id, 'user_id' => null]);
        $this->assertStringNotContainsString('tamu@lain.id', $res->getContent(), 'email tamu tidak bocor');
    }

    public function test_permissions_and_undo(): void
    {
        $event = $this->event();
        $card = $this->orgCard($this->member);
        $this->actingAs($this->member);
        $this->api('POST', "/events/$event/checkins", ['value' => config('tapcard.resolver_url')."/c/{$card->card_token}", 'method' => 'code'])->assertForbidden();

        $this->actingAs($this->panitia);
        $id = $this->api('POST', "/events/$event/checkins", ['value' => config('tapcard.resolver_url')."/c/{$card->card_token}", 'method' => 'code'])->assertCreated()->json('data.checkin.id');
        $this->api('DELETE', "/events/$event/checkins/$id")->assertForbidden(); // batal hanya owner/admin

        $this->actingAs($this->owner);
        $this->api('DELETE', "/events/$event/checkins/$id")->assertNoContent();
        $this->assertDatabaseHas('audit_logs', ['action' => 'event.checkin_removed']);
        $this->actingAs($this->panitia);
        $this->api('POST', "/events/$event/checkins", ['value' => config('tapcard.resolver_url')."/c/{$card->card_token}", 'method' => 'code'])->assertCreated();
    }
}
