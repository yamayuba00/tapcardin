<?php

namespace Tests\Feature;

use App\Enums\TenantType;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Dashboard: risiko panel (langganan, invoice, domain) dan funnel lead workspace. */
class DashboardOpsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_panel_counts_subscriptions_ending_failed_invoices_and_domain_problems(): void
    {
        $owner = $this->makeUser('owner@ops.id', 'Owner');
        $tenant = Tenant::create([
            'type' => TenantType::Company, 'name' => 'Ops Co', 'slug' => 'opsco', 'owner_user_id' => $owner->id,
            'status' => 'active', 'plan_id' => Plan::where('key', 'starter')->value('id'), 'settings' => Tenant::DEFAULT_SETTINGS,
        ]);
        $plan = Plan::where('key', 'starter')->value('id');
        Subscription::create(['tenant_id' => $tenant->id, 'plan_id' => $plan, 'status' => 'active', 'cycle' => 'semiannual',
            'current_period_start' => now()->subMonths(5), 'current_period_end' => now()->addDays(3)]);
        TenantDomain::create(['tenant_id' => $tenant->id, 'domain' => 'kartu.opsco.test', 'verify_token' => 'tok', 'status' => 'pending', 'last_error' => 'TXT belum ditemukan']);

        $this->actingAs($this->makeStaff('platform_owner', 'staf@tapcard.test', 'Staf'));
        $risk = $this->json('GET', $this->host('panel', '/api/dashboard'))->assertOk()->json('data.risk');
        $this->assertSame(1, $risk['subscriptions_ending']);
        $this->assertSame(0, $risk['invoices_failed']);
        $this->assertSame(1, $risk['domains_problem']);
    }

    public function test_workspace_funnel_counts_open_leads_per_stage_and_isolates_tenants(): void
    {
        $rina = $this->makeUser();
        $this->actingAs($rina);
        $this->json('POST', $this->host('app', '/api/leads'), ['name' => 'Sari'])->assertCreated();
        $this->json('POST', $this->host('app', '/api/leads'), ['name' => 'Budi'])->assertCreated();

        $budi = $this->makeUser('lain@example.com', 'Lain');
        $this->actingAs($budi);
        $this->json('POST', $this->host('app', '/api/leads'), ['name' => 'Orang lain'])->assertCreated();

        $this->actingAs($rina);
        $funnel = $this->json('GET', $this->host('app', '/api/dashboard'))->assertOk()->json('data.funnel');
        $this->assertSame(2, array_sum(array_column($funnel, 'count')), 'lead tenant lain tidak ikut dihitung');
        $this->assertSame(2, collect($funnel)->firstWhere('name', 'Baru')['count']);
    }
}
