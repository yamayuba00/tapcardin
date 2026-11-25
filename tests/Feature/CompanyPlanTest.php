<?php

namespace Tests\Feature;

use App\Authz\PlanFeatures;
use App\Enums\CardStatus;
use App\Models\Card;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Paket perusahaan: langganan workspace per 6 bulan, batas kartu aktif per paket, uji coba 14 hari, habis → dashboard terkunci. */
class CompanyPlanTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function panel(string $method, string $path, array $data = [])
    {
        return $this->json($method, $this->host('panel', "/api$path"), $data);
    }

    private function company(User $owner, string $plan = 'starter'): Tenant
    {
        $this->actingAs($this->makeStaff());
        $id = $this->panel('POST', '/tenants', ['name' => 'Maju Jaya', 'slug' => 'majujaya', 'owner_email' => $owner->email, 'plan' => $plan])
            ->assertCreated()->json('data.id');

        return Tenant::where('public_id', $id)->firstOrFail();
    }

    /** Kartu stok perusahaan yang ditugaskan ke anggota (belum aktif). */
    private function companyCard(Tenant $org, User $member): Card
    {
        $card = $this->makeCard(CardStatus::Unclaimed, buyer: $member);
        $card->update(['tenant_id' => $org->id]);

        return $card;
    }

    private function sub(Tenant $t): Subscription
    {
        return Subscription::acrossTenants()->where('tenant_id', $t->id)->firstOrFail();
    }

    public function test_company_plans_are_flat_semiannual_and_differ_by_card_cap(): void
    {
        $plans = Plan::where('tenant_type', 'company')->orderBy('sort')->get()->keyBy('key');
        $this->assertSame(['starter', 'growth', 'business', 'enterprise'], $plans->keys()->all());
        $this->assertFalse(Plan::where('key', 'team')->exists());
        foreach (['starter' => [10, 1000000], 'growth' => [40, 4000000], 'business' => [80, 10000000]] as $key => [$cards, $price]) {
            $p = $plans[$key];
            $this->assertSame([$cards, null, $price, ['semiannual'], false], [$p->limits['cards_active'], $p->limits['members'], (int) $p->price_semiannual, $p->cycles(), $p->per_seat]);
            $this->assertArrayNotHasKey('cards_per_member', $p->limits);
        }
        $this->assertTrue($plans['business']->features['custom_domain']);
        $this->assertFalse($plans['enterprise']->is_public);
        $this->assertSame(['monthly', 'yearly'], Plan::where('key', 'personal_pro')->first()->cycles());
    }

    public function test_panel_tabs_split_company_and_personal_with_card_usage_and_trial(): void
    {
        $owner = $this->makeUser('owner@majujaya.id', 'Owner MJ');
        $org = $this->company($owner);
        $sub = $this->sub($org);
        $this->assertSame(['trialing', 'semiannual'], [$sub->status, $sub->cycle]);
        $this->assertEqualsWithDelta(now()->addDays(14)->timestamp, $sub->trial_ends_at->timestamp, 5);

        $rows = $this->panel('GET', '/tenants?type=company')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame(['majujaya', 0, 10, 'trialing'], [$rows[0]['slug'], $rows[0]['cards_active'], $rows[0]['cards_max'], $rows[0]['subscription_status']]);
        $this->assertSame($sub->trial_ends_at->toIso8601String(), $rows[0]['active_until']);

        $personal = collect($this->panel('GET', '/tenants?type=personal')->assertOk()->json('data'));
        $this->assertNotEmpty($personal);
        $this->assertTrue($personal->every(fn ($t) => $t['type'] === 'personal'));
        $this->assertNull($personal->firstWhere('owner.email', 'owner@majujaya.id')['subscription_status']);
    }

    public function test_company_card_cap_counts_all_active_cards_of_the_workspace(): void
    {
        $owner = $this->makeUser('owner@majujaya.id', 'Owner MJ');
        $org = $this->company($owner);
        $org->update(['limit_overrides' => ['cards_active' => 2]]);
        PlanFeatures::forget($org);

        $this->actingAs($owner);
        foreach ([1, 2] as $_) {
            $this->postJson($this->host('app', '/api/cards/activate'), ['token' => $this->companyCard($org, $owner)->card_token])->assertOk();
        }
        $third = $this->companyCard($org, $owner);
        $this->postJson($this->host('app', '/api/cards/activate'), ['token' => $third->card_token])->assertStatus(402)->assertJsonPath('error.code', 'plan_limit_reached');

        // tanpa override: batas paket Starter (10)
        $org->update(['limit_overrides' => []]);
        PlanFeatures::forget($org);
        $this->assertSame(10, PlanFeatures::limit($org->fresh(), 'cards_active'));
    }

    public function test_semiannual_checkout_extends_trial_by_six_months(): void
    {
        $owner = $this->makeUser('owner@majujaya.id', 'Owner MJ');
        $org = $this->company($owner);
        $trialEnd = $this->sub($org)->current_period_end;
        $this->actingAs($owner);

        $billing = $this->getJson($this->host('majujaya', '/api/billing'))->assertOk()->json('data');
        $this->assertTrue($billing['company']);
        $this->assertSame(['starter', 'growth', 'business'], array_column($billing['plans'], 'key'));
        $this->assertSame(['semiannual' => 1000000], $billing['plans'][0]['prices']);
        $this->assertSame(10, $billing['plans'][0]['cards_max']);

        $this->postJson($this->host('majujaya', '/api/billing/checkout'), ['plan' => 'starter', 'cycle' => 'monthly'])->assertJsonPath('error.code', 'cycle_unavailable');
        $c = $this->postJson($this->host('majujaya', '/api/billing/checkout'), ['plan' => 'starter', 'cycle' => 'semiannual'])->assertCreated()->json('data');
        $this->assertSame(1000000, $c['invoice']['total']);
        $this->assertSame('Paket Starter — 6 bulan', $c['invoice']['lines'][0]['label']);
        $this->postJson($this->host('majujaya', "/api/billing/invoices/{$c['invoice']['id']}/simulate"), ['result' => 'paid', 'method' => 'QRIS'])->assertOk();

        $sub = $this->sub($org);
        $this->assertSame(['active', 'semiannual'], [$sub->status, $sub->cycle]);
        $this->assertEqualsWithDelta($trialEnd->copy()->addMonthsNoOverflow(6)->timestamp, $sub->current_period_end->timestamp, 5);
    }

    public function test_expired_company_locks_dashboard_but_cards_keep_working(): void
    {
        $owner = $this->makeUser('owner@majujaya.id', 'Owner MJ');
        $org = $this->company($owner);
        $card = $this->companyCard($org, $owner);
        $this->actingAs($owner)->postJson($this->host('app', '/api/cards/activate'), ['token' => $card->card_token])->assertOk();

        // uji coba habis → langsung berakhir (tanpa tenggang), bukan turun ke paket gratis
        $this->travel(15)->days();
        $this->artisan('tapcard:subscriptions')->assertSuccessful();
        $this->assertSame('expired', $this->sub($org)->status);
        $this->assertSame('starter', $org->fresh()->plan->key);
        $this->assertDatabaseHas('notifications', ['user_id' => $owner->id, 'type' => 'workspace_expired']);

        $this->actingAs($owner);
        $this->getJson($this->host('majujaya', '/api/dashboard'))->assertStatus(402)->assertJsonPath('error.code', 'workspace_expired');
        $this->getJson($this->host('majujaya', '/api/me'))->assertOk()->assertJsonPath('data.workspace.expired', true);
        $this->getJson($this->host('majujaya', '/api/billing'))->assertOk()->assertJsonPath('data.expired', true);
        $this->getJson($this->host('tap', "/api/c/{$card->card_token}"))->assertOk()->assertJsonPath('data.state', 'profile');

        // perpanjang → terbuka lagi, periode 6 bulan dari sekarang
        $c = $this->postJson($this->host('majujaya', '/api/billing/checkout'), ['plan' => 'starter', 'cycle' => 'semiannual'])->assertCreated()->json('data');
        $this->postJson($this->host('majujaya', "/api/billing/invoices/{$c['invoice']['id']}/simulate"), ['result' => 'paid', 'method' => 'QRIS'])->assertOk();
        $this->assertEqualsWithDelta(now()->addMonthsNoOverflow(6)->timestamp, $this->sub($org)->current_period_end->timestamp, 5);
        $this->getJson($this->host('majujaya', '/api/dashboard'))->assertOk();
    }

    public function test_unpaid_company_renewal_goes_past_due_then_expires_without_downgrade(): void
    {
        $owner = $this->makeUser('owner@majujaya.id', 'Owner MJ');
        $org = $this->company($owner, 'growth');
        $this->sub($org)->update(['status' => 'active', 'trial_ends_at' => null, 'current_period_end' => now()->addDays(5)]);

        $this->artisan('tapcard:subscriptions')->assertSuccessful();
        $inv = Invoice::acrossTenants()->where('tenant_id', $org->id)->where('status', 'issued')->firstOrFail();
        $this->assertSame(4000000, (int) $inv->total);
        $this->assertSame('Perpanjangan Growth — 6 bulan', $inv->lines[0]['label']);

        $this->travel(6)->days();
        $this->artisan('tapcard:subscriptions');
        $this->assertSame('past_due', $this->sub($org)->status);
        $this->actingAs($owner)->getJson($this->host('majujaya', '/api/dashboard'))->assertOk(); // masa tenggang: masih terbuka

        $this->travel(15)->days();
        $this->artisan('tapcard:subscriptions');
        $this->assertSame('expired', $this->sub($org)->status);
        $this->assertSame('growth', $org->fresh()->plan->key, 'perusahaan tidak turun ke paket gratis');
        $this->actingAs($owner)->getJson($this->host('majujaya', '/api/dashboard'))->assertStatus(402);
    }

    public function test_company_grant_is_in_six_month_units(): void
    {
        $org = $this->company($this->makeUser('owner@majujaya.id', 'Owner MJ'));
        $this->actingAs($this->makeStaff('platform_owner', 'fin@tapcard.test'));
        $body = ['tenant' => $org->public_id, 'plan' => 'starter', 'reason' => 'Kompensasi gangguan', 'reference' => 'TCK-1'];
        $this->panel('POST', '/finance/grant', ['months' => 3] + $body)->assertJsonPath('error.code', 'grant_semiannual');
        $this->panel('POST', '/finance/grant', ['months' => 12] + $body)->assertStatus(202);
    }
}
