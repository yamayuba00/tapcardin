<?php

namespace Tests\Feature;

use App\Enums\TenantType;
use App\Models\Design;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Harga paket perusahaan (1 jt / 4 jt / 10 jt per 6 bulan, batas kartu 10 / 40 / 80) dan potongan kartu fisik Rp20.000. */
class CompanyPricingTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_company_plans_have_the_agreed_price_and_card_cap(): void
    {
        foreach (['starter' => [1000000, 10], 'growth' => [4000000, 40], 'business' => [10000000, 80]] as $key => [$price, $cards]) {
            $plan = Plan::where('key', $key)->firstOrFail();
            $this->assertSame($price, (int) $plan->price_semiannual, "$key harga 6 bulan");
            $this->assertSame($cards, (int) $plan->limits['cards_active'], "$key batas kartu");
        }
    }

    public function test_company_workspace_gets_card_discount_personal_does_not(): void
    {
        $owner = $this->makeUser('owner@majujaya.id', 'Owner MJ');
        $org = Tenant::create([
            'type' => TenantType::Company, 'name' => 'Maju Jaya', 'slug' => 'majujaya', 'owner_user_id' => $owner->id,
            'status' => 'active', 'plan_id' => Plan::where('key', 'starter')->value('id'), 'settings' => Tenant::DEFAULT_SETTINGS,
        ]);
        app(CurrentTenant::class)->run($org, fn () => TenantMember::create(['user_id' => $owner->id, 'role_id' => Role::system('owner')->id, 'status' => 'active']));

        $this->actingAs($owner);
        $design = $this->json('POST', $this->host('majujaya', '/api/designs'), ['template' => 'onyx', 'orientation' => 'landscape'])->assertCreated()->json('data.id');
        Design::withoutGlobalScope(TenantScope::class)->where('public_id', $design)->update(['validated_at' => now()]);
        $company = $this->json('POST', $this->host('majujaya', '/api/orders/quote'), [
            'items' => [['kind' => 'card', 'design' => $design, 'product' => 'pvc_ntag215', 'quantity' => 10]], 'method' => 'regular',
        ])->assertOk()->json('data.subtotal');
        $this->assertSame(10 * (65000 - 20000), $company, 'potongan Rp20.000 per kartu fisik');

        $rina = $this->makeUser();
        $this->actingAs($rina);
        $personalDesign = $this->json('POST', $this->host('app', '/api/designs'), ['template' => 'onyx', 'orientation' => 'landscape'])->assertCreated()->json('data.id');
        Design::withoutGlobalScope(TenantScope::class)->where('public_id', $personalDesign)->update(['validated_at' => now()]);
        $personal = $this->json('POST', $this->host('app', '/api/orders/quote'), [
            'items' => [['kind' => 'card', 'design' => $personalDesign, 'product' => 'pvc_ntag215', 'quantity' => 10]], 'method' => 'regular',
        ])->assertOk()->json('data.subtotal');
        $this->assertSame(10 * 65000, $personal, 'workspace pribadi tanpa potongan');
    }
}
