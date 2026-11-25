<?php

namespace Tests\Feature;

use App\Actions\Leads\CreateConnection;
use App\Enums\LeadSource;
use App\Enums\TenantType;
use App\Models\Connection;
use App\Models\LeadStage;
use App\Models\Notification;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Notifikasi lead tidak boleh menunjuk lead yang sudah tidak ada: disaring di daftar, dihapus saat lead dihapus. */
class LeadNotificationGoneTest extends TestCase
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
        $this->budi = $this->makeUser('budi@majujaya.id', 'Budi');
        $this->org = Tenant::create([
            'type' => TenantType::Company, 'name' => 'Maju Jaya', 'slug' => 'maju-jaya-mandiri', 'owner_user_id' => $this->owner->id,
            'status' => 'active', 'plan_id' => Plan::where('key', 'business')->value('id'), 'settings' => Tenant::DEFAULT_SETTINGS,
        ]);
        app(CurrentTenant::class)->run($this->org, function () {
            foreach (LeadStage::DEFAULTS as $i => $s) {
                LeadStage::create([...$s, 'sort' => $i]);
            }
            TenantMember::create(['user_id' => $this->owner->id, 'role_id' => Role::system('owner')->id, 'status' => 'active']);
            TenantMember::create(['user_id' => $this->budi->id, 'role_id' => Role::system('member')->id, 'status' => 'active']);
        });
    }

    private function lead(string $phone, string $name): Connection
    {
        return app(CurrentTenant::class)->run($this->org, fn () => app(CreateConnection::class)
            ->handle(['name' => $name, 'phone' => $phone], LeadSource::Manual, $this->budi->id)['connection']);
    }

    private function notify(string $type, string $leadId): Notification
    {
        return Notification::create(['user_id' => $this->owner->id, 'tenant_id' => $this->org->id, 'type' => $type,
            'data' => ['id' => $leadId, 'name' => 'Lead']]);
    }

    public function test_list_and_unread_count_exclude_notifications_of_missing_leads(): void
    {
        $live = $this->lead('081200000011', 'Sari');
        $this->notify('lead_new', $live->public_id);
        $gone = $this->notify('lead_new', '01M7T2XRYFK11QSTVNRRJZDG2D');
        $reminderGone = $this->notify('reminder_due', '01M7T2XRYFK11QSTVNRRJZDH0');
        $tap = Notification::create(['user_id' => $this->owner->id, 'tenant_id' => $this->org->id, 'type' => 'tap', 'data' => ['card' => 'ABCDEFGHIJ']]);

        $res = $this->actingAs($this->owner)->getJson($this->host('maju-jaya-mandiri', '/api/notifications'))->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertNotContains($gone->id, $ids);
        $this->assertNotContains($reminderGone->id, $ids);
        $this->assertContains($tap->id, $ids);
        $this->assertCount(2, $ids);
        $res->assertJsonPath('meta.unread', 2);
    }

    public function test_deleting_lead_removes_its_lead_notifications_only(): void
    {
        $sari = $this->lead('081200000012', 'Sari');
        $budi = $this->lead('081200000013', 'Budi Lain');
        $a = $this->notify('lead_new', $sari->public_id);
        $b = $this->notify('reminder_due', $sari->public_id);
        $other = $this->notify('lead_new', $budi->public_id);
        $tap = Notification::create(['user_id' => $this->owner->id, 'tenant_id' => $this->org->id, 'type' => 'tap', 'data' => ['card' => 'ABCDEFGHIJ']]);

        $this->actingAs($this->owner)->deleteJson($this->host('maju-jaya-mandiri', '/api/leads/'.$sari->public_id))->assertNoContent();

        $this->assertDatabaseMissing('notifications', ['id' => $a->id]);
        $this->assertDatabaseMissing('notifications', ['id' => $b->id]);
        $this->assertDatabaseHas('notifications', ['id' => $other->id]);
        $this->assertDatabaseHas('notifications', ['id' => $tap->id]);
    }

    public function test_merged_lead_detail_exposes_merge_target(): void
    {
        $target = $this->lead('081200000014', 'Tujuan');
        $dup = $this->lead('081200000015', 'Duplikat');
        app(CurrentTenant::class)->run($this->org, fn () => Connection::whereKey($dup->id)->update(['merged_into_id' => $target->id]));

        $this->actingAs($this->owner)->getJson($this->host('maju-jaya-mandiri', '/api/leads/'.$dup->public_id))
            ->assertOk()->assertJsonPath('data.merged_into', $target->public_id);
    }
}
