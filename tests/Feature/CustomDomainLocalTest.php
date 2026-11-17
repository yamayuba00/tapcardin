<?php

namespace Tests\Feature;

use App\Enums\TenantType;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\TenantMember;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Local custom domain test: majujaya.localhost → workspace. */
class CustomDomainLocalTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_local_custom_domain_routes_to_workspace(): void
    {
        $owner = $this->makeUser('majujaya@local.test', 'Maju Jaya');
        $org = Tenant::create([
            'type' => TenantType::Company,
            'name' => 'Maju Jaya',
            'slug' => 'majujaya',
            'owner_user_id' => $owner->id,
            'status' => 'active',
            'plan_id' => Plan::where('key', 'business')->value('id'),
            'settings' => Tenant::DEFAULT_SETTINGS,
        ]);
        app(CurrentTenant::class)->run($org, function () use ($owner) {
            TenantMember::create([
                'user_id' => $owner->id,
                'role_id' => Role::system('owner')->id,
                'status' => 'active',
            ]);
        });

        // Create custom domain: majujaya.localhost
        TenantDomain::create([
            'tenant_id' => $org->id,
            'domain' => 'majujaya.localhost',
            'verify_token' => 'test-token-123',
            'status' => 'active',
            'verified_at' => now(),
        ]);

        // Access via custom domain
        $this->actingAs($owner);
        $res = $this->getJson('http://majujaya.localhost/api/billing');
        $this->assertSame(200, $res->status());
        $data = $res->json('data');
        $this->assertIsArray($data);
        $this->assertArrayHasKey('plan', $data);
    }
}
