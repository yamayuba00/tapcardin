<?php

namespace Tests\Feature;

use App\Enums\CardStatus;
use App\Enums\TenantType;
use App\Models\Card;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Laporan kartu owner: masa berlaku semua kartu (aktif, akan berakhir, tenggang, nonaktif) dan rekap per anggota. */
class CardReportTest extends TestCase
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
        $this->join($this->owner, 'owner');
    }

    private function join(User $user, string $role): void
    {
        app(CurrentTenant::class)->run($this->org, fn () => TenantMember::create(['user_id' => $user->id, 'role_id' => Role::system($role)->id, 'status' => 'active']));
    }

    /** kartu fisik milik workspace ini dengan masa berlaku tertentu (hari relatif dari sekarang) */
    private function physical(?User $holder, ?int $days): Card
    {
        $card = $this->makeCard($holder ? CardStatus::Active : CardStatus::Unclaimed, $holder);
        $card->forceFill([
            'tenant_id' => $this->org->id, 'form' => Card::PHYSICAL, 'valid_until' => $days === null ? null : now()->addDays($days)->startOfSecond(),
            'activated_at' => now()->subMonths(1),
        ])->save();

        return $card;
    }

    private function report()
    {
        return $this->json('GET', $this->host('majujaya', '/api/reports/cards'));
    }

    public function test_owner_sees_validity_state_of_every_card(): void
    {
        $budi = $this->makeUser('budi@majujaya.id', 'Budi');
        $this->join($budi, 'member');
        $this->physical($budi, 60);   // aktif
        $this->physical($budi, 10);   // aktif, akan berakhir
        $this->physical(null, null); // belum dipegang, belum punya masa berlaku
        $this->physical($budi, -2);   // tenggang
        $this->physical($budi, -20);  // nonaktif (lewat tenggang)

        $this->actingAs($this->owner);
        $res = $this->report()->assertOk();

        $res->assertJsonPath('meta.totals.cards', 5)
            ->assertJsonPath('meta.totals.active', 2)
            ->assertJsonPath('meta.totals.grace', 1)
            ->assertJsonPath('meta.totals.expired', 1)
            ->assertJsonPath('meta.totals.expiring', 1);
        $states = collect($res->json('data'))->pluck('state')->sort()->values()->all();
        $this->assertSame(['active', 'active', 'expired', 'grace', 'none'], $states);
        $this->assertCount(5, $res->json('data'));
        // kartu yang paling dekat jatuh tempo tampil paling atas
        $this->assertSame('expired', $res->json('data.0.state'));
    }

    public function test_members_rekap_groups_cards_by_holder(): void
    {
        $budi = $this->makeUser('budi@majujaya.id', 'Budi');
        $sari = $this->makeUser('sari@majujaya.id', 'Sari');
        $this->join($budi, 'member');
        $this->join($sari, 'member');
        $this->physical($budi, 60);
        $this->physical($budi, 10);
        $this->physical($sari, 90);

        $this->actingAs($this->owner);
        $members = collect($this->report()->assertOk()->json('meta.members'))->keyBy('holder.email');

        $this->assertSame(2, $members['budi@majujaya.id']['cards']);
        $this->assertSame(1, $members['budi@majujaya.id']['expiring']);
        $this->assertSame(1, $members['sari@majujaya.id']['cards']);
        $this->assertSame(0, $members['sari@majujaya.id']['expiring']);
    }

    public function test_member_without_card_visibility_is_refused(): void
    {
        $budi = $this->makeUser('budi@majujaya.id', 'Budi');
        $this->join($budi, 'member');

        $this->actingAs($budi);
        $this->report()->assertStatus(403);
    }
}
