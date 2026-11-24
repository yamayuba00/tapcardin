<?php

namespace Tests\Feature;

use App\Authz\PlanFeatures;
use App\Enums\CardStatus;
use App\Models\Invoice;
use App\Models\StaffAccount;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\TenantMember;
use App\Models\Voucher;
use App\Support\Dns\DnsResolver;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Panel — paket & harga, keuangan, voucher, domain kustom, katalog, pengumuman. */
class PanelBusinessTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function staff(string $role, string $email = 'staf@tapcard.test'): StaffAccount
    {
        $user = $this->makeStaff($role, $email, 'Staf');

        return $user;
    }

    private function panel(string $method, string $path, array $data = [])
    {
        return $this->json($method, $this->host('panel', "/api$path"), $data);
    }

    private function app(string $method, string $path, array $data = [])
    {
        return $this->json($method, $this->host('app', "/api$path"), $data);
    }

    public function test_plan_price_change_is_used_by_checkout_and_requires_plans_permission(): void
    {
        $this->actingAs($this->staff('platform_admin', 'admin@tapcard.test'));
        $this->panel('PATCH', '/plans/personal_pro', ['price_monthly' => 59000])->assertForbidden();

        $this->actingAs($this->staff('platform_owner'));
        $plans = $this->panel('GET', '/plans')->assertOk()->json('data');
        $this->assertContains('custom_domain', array_keys($this->panel('GET', '/plans')->json('meta.features')));
        $this->assertNotEmpty($plans);
        $this->panel('PATCH', '/plans/personal_pro', ['price_monthly' => 59000])->assertOk()->assertJsonPath('data.price_monthly', 59000);
        $this->assertDatabaseHas('audit_logs', ['action' => 'plan.updated', 'scope' => 'platform']);

        $this->actingAs($this->makeUser());
        $this->app('POST', '/billing/checkout', ['plan' => 'personal_pro', 'cycle' => 'monthly'])->assertCreated()->assertJsonPath('data.invoice.total', 59000);
    }

    public function test_voucher_discount_preview_checkout_and_single_redemption(): void
    {
        $this->actingAs($this->staff('platform_owner'));
        $this->panel('POST', '/vouchers', ['code' => 'bad code', 'kind' => 'percent', 'value' => 10])->assertUnprocessable();
        $this->panel('POST', '/vouchers', ['code' => 'x', 'kind' => 'percent', 'value' => 150])->assertUnprocessable();
        $this->panel('POST', '/vouchers', ['code' => 'launch25', 'kind' => 'percent', 'value' => 25, 'plan_keys' => ['personal_pro'], 'max_redemptions' => 10])
            ->assertCreated()->assertJsonPath('data.code', 'LAUNCH25');

        $rina = $this->makeUser();
        $this->actingAs($rina);
        $this->app('POST', '/billing/voucher', ['code' => 'nope', 'plan' => 'personal_pro', 'cycle' => 'monthly'])->assertJsonPath('error.code', 'voucher_invalid');
        $this->app('POST', '/billing/voucher', ['code' => 'launch25', 'plan' => 'personal_pro', 'cycle' => 'monthly'])
            ->assertOk()->assertJsonPath('data.discount', 12250)->assertJsonPath('data.total', 36750);

        $inv = $this->app('POST', '/billing/checkout', ['plan' => 'personal_pro', 'cycle' => 'monthly', 'voucher' => 'LAUNCH25'])->assertCreated()->json('data.invoice');
        $this->assertSame(36750, $inv['total']);
        $this->app('POST', "/billing/invoices/{$inv['id']}/simulate", ['result' => 'paid', 'method' => 'QRIS'])->assertJsonPath('data.status', 'paid');
        $this->assertSame(1, Voucher::where('code', 'LAUNCH25')->value('redeemed_count'));
        $this->assertDatabaseCount('voucher_redemptions', 1);
        $this->app('POST', '/billing/voucher', ['code' => 'launch25', 'plan' => 'personal_pro', 'cycle' => 'monthly'])
            ->assertJsonPath('error.code', 'voucher_invalid')->assertJsonPath('error.message', 'Voucher ini sudah pernah dipakai workspace Anda.');
    }

    public function test_full_voucher_activates_plan_without_gateway(): void
    {
        Voucher::create(['code' => 'GRATIS', 'kind' => 'percent', 'value' => 100]);
        $rina = $this->makeUser();
        $this->actingAs($rina);
        $r = $this->app('POST', '/billing/checkout', ['plan' => 'personal_pro', 'cycle' => 'monthly', 'voucher' => 'gratis'])->assertCreated()->json('data');
        $this->assertNull($r['payment_url']);
        $this->assertSame('paid', $r['invoice']['status']);
        $rina->personalTenant->refresh()->unsetRelation('plan');
        $this->assertSame('personal_pro', $rina->personalTenant->plan->key);
    }

    public function test_finance_mark_paid_void_grant_and_metrics(): void
    {
        $rina = $this->makeUser();
        $this->actingAs($rina);
        $inv = $this->app('POST', '/billing/checkout', ['plan' => 'personal_pro', 'cycle' => 'monthly'])->json('data.invoice');

        $this->actingAs($this->staff('support', 'support@tapcard.test'));
        $this->panel('GET', '/finance/invoices')->assertForbidden();

        // hanya satu staf berwenang (Pemilik bawaan seeder dicabut) → mode tunggal: boleh menyetujui sendiri, tercatat
        StaffAccount::where('email', 'owner@tapcard.test')->update(['status' => 'revoked']);
        $this->actingAs($this->staff('platform_owner'));
        $this->panel('GET', '/finance/invoices?status=issued')->assertOk()->assertJsonPath('meta.total', 1);
        // tandai lunas manual TIDAK langsung berjalan: jadi permintaan persetujuan (hanya satu staf berwenang → mode tunggal)
        $req = $this->panel('POST', "/finance/invoices/{$inv['id']}/mark-paid", ['method' => 'Transfer BCA', 'reference' => 'TRX-1001'])
            ->assertStatus(202)->assertJsonPath('data.status', 'pending')->json('data');
        $this->panel('GET', "/finance/invoices/{$inv['id']}")->assertJsonPath('data.status', 'issued');
        $this->panel('POST', "/approvals/{$req['id']}/approve")->assertOk()->assertJsonPath('data.self_approved', true);
        $this->panel('GET', "/finance/invoices/{$inv['id']}")->assertJsonPath('data.status', 'paid');
        $this->panel('POST', "/finance/invoices/{$inv['id']}/void", ['reason' => 'sudah lunas', 'reference' => 'TKT-1'])->assertJsonPath('error.code', 'invoice_not_payable');
        $this->assertSame(49000, $this->panel('GET', '/finance/summary')->json('data.revenue_month'));
        $this->assertSame(49000, $this->panel('GET', '/dashboard')->json('data.business.mrr'));

        $lain = $this->makeUser('lain@example.com', 'Lain');
        $grant = $this->panel('POST', '/finance/grant', ['tenant' => $lain->personalTenant->public_id, 'plan' => 'personal_pro', 'months' => 3, 'reason' => 'Mitra resmi', 'reference' => 'TKT-77'])
            ->assertStatus(202)->json('data');
        $this->assertNull(Subscription::withoutGlobalScope(TenantScope::class)->where('tenant_id', $lain->personalTenant->id)->first(), 'belum berjalan sebelum disetujui');
        $this->panel('POST', "/approvals/{$grant['id']}/approve")->assertOk();
        $sub = Subscription::withoutGlobalScope(TenantScope::class)->where('tenant_id', $lain->personalTenant->id)->firstOrFail();
        $this->assertSame('active', $sub->status);
        $this->assertEqualsWithDelta(now()->addMonths(3)->timestamp, $sub->current_period_end->timestamp, 5);
        $this->assertSame(0, (int) Invoice::withoutGlobalScope(TenantScope::class)->where('tenant_id', $lain->personalTenant->id)->value('total'));

        $tenant = $this->panel('GET', "/tenants/{$lain->personalTenant->public_id}")->assertOk()->json('data');
        $this->assertSame('Personal Pro', $tenant['subscription']['plan']);
        $this->assertCount(1, $tenant['invoices']);
    }

    public function test_custom_domain_verify_via_dns_and_route_to_workspace(): void
    {
        $rina = $this->makeUser();
        $tenant = $rina->personalTenant;
        $tenant->update(['limit_overrides' => ['features' => ['custom_domain' => true]]]); // paket perorangan tidak punya fitur ini: beri lewat override
        PlanFeatures::forget($tenant);
        $this->actingAs($this->staff('platform_owner'));
        $this->panel('POST', '/domains', ['tenant' => $tenant->public_id, 'domain' => 'kartu.localhost'])->assertJsonPath('error.code', 'domain_reserved');
        $d = $this->panel('POST', '/domains', ['tenant' => $tenant->public_id, 'domain' => 'https://Kartu.Acme.com/'])->assertCreated()->json('data');
        $this->assertSame('kartu.acme.com', $d['domain']);
        $this->assertSame('pending', $d['status']);
        $this->panel('POST', '/domains', ['tenant' => $tenant->public_id, 'domain' => 'kartu.acme.com'])->assertUnprocessable();

        $token = TenantDomain::where('domain', 'kartu.acme.com')->value('verify_token');
        $this->app->instance(DnsResolver::class, new class extends DnsResolver
        {
            public array $records = [];

            public function txt(string $name): array
            {
                return $this->records[$name] ?? [];
            }
        });
        $this->panel('POST', "/domains/{$d['id']}/verify")->assertStatus(202)->assertJsonPath('data.status', 'pending')->assertJsonPath('data.last_error', fn ($e) => str_contains($e, 'belum ditemukan'));

        $this->actingAs($rina);
        $this->getJson('http://kartu.acme.com/api/billing')->assertNotFound();

        app(DnsResolver::class)->records['_tapcard.kartu.acme.com'] = ["tapcard-verify=$token"];
        $this->actingAs($this->staff('platform_owner', 'owner2@tapcard.test'));
        $this->panel('POST', "/domains/{$d['id']}/verify")->assertStatus(202)->assertJsonPath('data.status', 'active');

        $this->actingAs($rina);
        $this->getJson('http://kartu.acme.com/api/billing')->assertOk()->assertJsonPath('data.plan.key', 'personal_free');
        $this->actingAs($this->makeUser('lain@example.com', 'Lain'));
        $this->getJson('http://kartu.acme.com/api/billing')->assertForbidden();

        $this->actingAs($this->staff('platform_owner', 'owner3@tapcard.test'));
        $this->panel('PATCH', "/domains/{$d['id']}", ['status' => 'disabled'])->assertOk();
        $this->actingAs($rina);
        $this->getJson('http://kartu.acme.com/api/billing')->assertNotFound();
    }

    public function test_panel_creates_company_workspace_with_subdomain(): void
    {
        $owner = $this->makeUser('owner@majujaya.id', 'Owner MJ');
        $this->actingAs($this->staff('platform_owner'));
        $body = ['name' => 'Maju Jaya', 'slug' => 'majujaya', 'owner_email' => 'owner@majujaya.id', 'plan' => 'business'];

        $this->panel('POST', '/tenants', ['slug' => 'admin'] + $body)->assertUnprocessable();
        $this->panel('POST', '/tenants', ['owner_email' => 'nobody@x.id'] + $body)->assertJsonPath('error.code', 'owner_not_found');
        $this->panel('POST', '/tenants', ['plan' => 'personal_free'] + $body)->assertUnprocessable();
        $res = $this->panel('POST', '/tenants', $body)->assertCreated();
        $res->assertJsonPath('data.slug', 'majujaya')->assertJsonPath('data.type', 'company');
        $this->assertStringContainsString('majujaya.', $res->json('data.url'));
        $this->panel('POST', '/tenants', $body)->assertUnprocessable();

        $this->actingAs($owner);
        $this->getJson($this->host('majujaya', '/api/billing'))->assertOk()->assertJsonPath('data.plan.key', 'business');
    }

    public function test_panel_transfers_workspace_ownership(): void
    {
        $old = $this->makeUser('lama@majujaya.id', 'Owner Lama');
        $new = $this->makeUser('baru@majujaya.id', 'Owner Baru');
        $this->actingAs($this->staff('platform_owner'));
        $id = $this->panel('POST', '/tenants', ['name' => 'Maju Jaya', 'slug' => 'majujaya', 'owner_email' => $old->email, 'plan' => 'business'])
            ->assertCreated()->json('data.id');

        $this->panel('PUT', "/tenants/$id/owner", ['user' => $old->public_id])->assertJsonPath('error.code', 'owner_same');
        $this->panel('PUT', "/tenants/{$old->personalTenant->public_id}/owner", ['user' => $new->public_id])->assertJsonPath('error.code', 'owner_fixed');
        $this->panel('PUT', "/tenants/$id/owner", ['user' => $new->public_id])->assertOk()->assertJsonPath('data.owner.email', 'baru@majujaya.id');

        $role = fn ($u) => TenantMember::acrossTenants()->with('role')->where('tenant_id', Tenant::where('public_id', $id)->value('id'))->where('user_id', $u->id)->first()->role->key;
        $this->assertSame('owner', $role($new));
        $this->assertSame('admin', $role($old));
        $this->assertDatabaseHas('audit_logs', ['action' => 'tenant.owner_changed', 'scope' => 'platform']);

        $this->actingAs($new);
        $this->getJson($this->host('majujaya', '/api/billing'))->assertOk();
    }

    public function test_catalog_prices_and_contact_types_and_announcement(): void
    {
        $this->actingAs($this->staff('platform_owner'));
        $c = $this->panel('GET', '/catalog')->assertOk()->json('data');
        $this->assertNotEmpty($c['lanyard_widths']);
        $w = $c['lanyard_widths'][0];
        $this->panel('PATCH', "/catalog/lanyard-widths/{$w['public_id']}", ['price_single' => 12345])->assertOk();
        $this->assertDatabaseHas('lanyard_widths', ['public_id' => $w['public_id'], 'price_single' => 12345]);
        $this->panel('POST', '/catalog/lanyard-hardware', ['name' => 'Pengait Emas', 'price' => 5000])->assertOk();
        $this->assertDatabaseHas('lanyard_hardware', ['name' => 'Pengait Emas', 'price' => 5000]);

        $type = $c['contact_types'][0];
        $this->panel('PATCH', "/catalog/contact-types/{$type['public_id']}", ['is_active' => false])->assertOk();
        $this->assertDatabaseHas('contact_types', ['public_id' => $type['public_id'], 'is_active' => false]);

        $this->makeUser();
        $this->panel('POST', '/announcements', ['title' => 'Fitur baru', 'body' => 'Mode Event sudah tersedia.', 'audience' => 'personal'])->assertCreated();
        $this->assertDatabaseHas('notifications', ['type' => 'announcement']);
        $this->assertGreaterThanOrEqual(1, $this->panel('GET', '/announcements')->json('data.0.recipients'));
    }

    /** Regresi: setiap halaman daftar panel harus terbuka (mis. /audit pernah 500 karena argumen Table::json kurang). */
    public function test_every_panel_list_endpoint_responds_for_owner(): void
    {
        $this->makeCard(CardStatus::Active, $this->makeUser());
        $this->actingAs($this->staff('platform_owner'));
        $this->panel('PATCH', '/plans/personal_pro', ['price_monthly' => 49000]); // isi audit log
        foreach (['announcements', 'audit', 'batches', 'cards', 'catalog', 'dashboard', 'domains', 'finance/events', 'finance/invoices',
            'finance/subscriptions', 'finance/summary', 'me', 'plans', 'staff', 'stations/batches', 'system', 'tenants', 'users', 'vouchers'] as $path) {
            $this->panel('GET', "/$path?sort=")->assertOk();
        }
        $this->panel('GET', '/audit?scope=platform&action=plan.')->assertOk()->assertJsonPath('data.0.action', 'plan.updated')->assertJsonPath('meta.per_page', 50);
    }
}
