<?php

namespace Tests\Feature;

use App\Actions\Leads\CreateConnection;
use App\Enums\LeadSource;
use App\Enums\TenantType;
use App\Models\LeadStage;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Tautan notifikasi lead (/connections/{public_id}) membuka detail lead; lead yang tidak ada tetap 404 JSON. */
class LeadNotificationLinkTest extends TestCase
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

    /** Lead dibuat oleh Budi (penerima notifikasi lead_new); API detail dipanggil dengan public_id dari tautan. */
    public function test_notification_link_opens_lead_detail_for_collector_and_owner(): void
    {
        $publicId = app(CurrentTenant::class)->run($this->org, fn () => app(CreateConnection::class)
            ->handle(['name' => 'Sari', 'phone' => '081200000001'], LeadSource::Manual, $this->budi->id)['connection']->public_id);

        $this->actingAs($this->budi)->getJson($this->host('maju-jaya-mandiri', '/api/leads/'.$publicId))
            ->assertOk()->assertJsonPath('data.name', 'Sari');
        $this->actingAs($this->owner)->getJson($this->host('maju-jaya-mandiri', '/api/leads/'.$publicId))
            ->assertOk();
    }

    /** Lead yang sudah tidak ada (dihapus) → 404 JSON; halaman detail kini menampilkan keadaan galat, bukan kerangka muat. */
    public function test_missing_lead_returns_404_json(): void
    {
        $this->actingAs($this->owner)->getJson($this->host('maju-jaya-mandiri', '/api/leads/01M7T2XRYFK11QSTVNRRJZDG2D'))
            ->assertNotFound()->assertJsonPath('error.code', 'not_found');
    }
}
