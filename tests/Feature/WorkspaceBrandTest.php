<?php

namespace Tests\Feature;

use App\Enums\TenantType;
use App\Models\BrandKit;
use App\Models\LeadStage;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Logo organisasi (perusahaan / komunitas / partai): diunggah admin ke disk public (S3), dipakai semua anggota. */
class WorkspaceBrandTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Tenant $org;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->owner = $this->makeUser('owner@partai.id', 'Owner');
        $this->org = Tenant::create([
            'type' => TenantType::Company, 'name' => 'Partai Maju', 'slug' => 'partaimaju', 'owner_user_id' => $this->owner->id,
            'status' => 'active', 'plan_id' => Plan::where('key', 'business')->value('id'), 'settings' => Tenant::DEFAULT_SETTINGS,
        ]);
        app(CurrentTenant::class)->run($this->org, function () {
            foreach (LeadStage::DEFAULTS as $i => $s) {
                LeadStage::create([...$s, 'sort' => $i]);
            }
        });
        $this->join($this->owner, 'owner');
    }

    private function join(User $user, string $role): void
    {
        app(CurrentTenant::class)->run($this->org, fn () => TenantMember::create([
            'user_id' => $user->id, 'role_id' => Role::system($role)->id, 'status' => 'active',
        ]));
    }

    private function api(string $method, string $path, array $data = [], string $host = 'partaimaju')
    {
        return $this->json($method, $this->host($host, "/api$path"), $data);
    }

    public function test_admin_uploads_logo_to_public_disk_and_members_designs_use_it(): void
    {
        $this->actingAs($this->owner);
        $this->api('PATCH', '/workspace/brand', ['org_kind' => 'party'])->assertOk()->assertJsonPath('data.org_kind_label', 'Partai politik');
        $res = $this->api('POST', '/workspace/brand/logo', ['image' => UploadedFile::fake()->image('logo.png', 800, 400)])->assertOk();

        $path = $this->org->fresh()->logo_path;
        $this->assertStringStartsWith("t{$this->org->id}/brand/logo-", $path);
        Storage::disk('public')->assertExists($path);
        $this->assertNotNull($res->json('data.logo_url'));
        $kit = BrandKit::withoutGlobalScopes()->where('tenant_id', $this->org->id)->where('is_workspace', true)->sole();
        $this->assertSame($path, $kit->logo_path);
        $this->assertSame(800, $kit->logo_px_w);

        // anggota biasa: tidak boleh mengganti logo, tapi kit organisasi otomatis terpasang di desain barunya
        $member = $this->makeUser('anggota@partai.id');
        $this->join($member, 'member');
        $this->actingAs($member);
        $this->api('POST', '/workspace/brand/logo', ['image' => UploadedFile::fake()->image('x.png', 100, 100)])->assertForbidden();
        $this->api('GET', '/workspace/brand')->assertOk()->assertJsonPath('data.can_edit', false);
        $d = $this->api('POST', '/designs', ['template' => 'onyx', 'orientation' => 'landscape'])->assertCreated()->json('data');
        $this->assertSame($kit->public_id, $d['kit']['id']);
        $this->assertTrue($d['kit']['workspace']);
        $kits = collect($this->api('GET', '/studio/templates')->assertOk()->json('data.kits'));
        $this->assertSame($kit->public_id, $kits->first()['id']);

        // ganti logo → file lama dihapus; hapus logo → kosong
        $this->actingAs($this->owner);
        $this->api('POST', '/workspace/brand/logo', ['image' => UploadedFile::fake()->image('baru.png', 600, 600)])->assertOk();
        Storage::disk('public')->assertMissing($path);
        $this->api('DELETE', '/workspace/brand/logo')->assertOk()->assertJsonPath('data.logo_url', null);
        $this->assertNull($kit->fresh()->logo_path);
    }

    public function test_personal_workspace_uses_brand_kits_instead(): void
    {
        $this->actingAs($this->makeUser());
        $this->api('GET', '/workspace/brand', host: 'app')->assertStatus(422);
    }
}
