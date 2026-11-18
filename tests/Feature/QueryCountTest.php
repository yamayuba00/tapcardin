<?php

namespace Tests\Feature;

use App\Enums\CardStatus;
use App\Enums\TenantType;
use App\Models\Card;
use App\Models\Connection;
use App\Models\Event;
use App\Models\Invoice;
use App\Models\LeadStage;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\TenantMember;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Endpoint daftar tidak boleh N+1: jumlah query sama untuk 3 baris maupun 200+ baris. */
class QueryCountTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private int $queries = 0;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::listen(function () {
            $this->queries++;
        });
    }

    /** Panggil dua kali (yang pertama memanaskan cache), hitung query panggilan kedua. */
    private function measure(string $url): int
    {
        $this->getJson($url)->assertOk();
        $this->queries = 0;
        $this->getJson($url)->assertOk();

        return $this->queries;
    }

    /**
     * @param  string|string[]  $urls  diukur pada data yang sama
     * @param  callable(int): void  $seed
     */
    private function assertConstant(string|array $urls, callable $seed): void
    {
        $urls = (array) $urls;
        $seed(3);
        $small = array_map(fn ($u) => $this->measure($u), $urls);
        $seed(205);
        foreach ($urls as $i => $url) {
            $large = $this->measure($url);
            $this->assertSame($small[$i], $large, "$url: {$small[$i]} query untuk 3 baris, $large untuk 208 baris (N+1)");
        }
    }

    private function user(): User
    {
        $n = ++$this->seq;
        $u = User::create(['email' => "u$n@load.test", 'name' => "Pengguna $n"]);
        $t = Tenant::create(['type' => TenantType::Personal, 'name' => $u->name, 'owner_user_id' => $u->id, 'status' => 'active',
            'plan_id' => Plan::where('key', 'personal_free')->value('id'), 'settings' => Tenant::DEFAULT_SETTINGS]);
        $u->forceFill(['personal_tenant_id' => $t->id])->save();

        return $u;
    }

    private function company(string $slug, User $owner): Tenant
    {
        $t = Tenant::create(['type' => TenantType::Company, 'name' => "PT $slug", 'slug' => $slug, 'owner_user_id' => $owner->id, 'status' => 'active',
            'plan_id' => Plan::where('key', 'business')->value('id'), 'settings' => Tenant::DEFAULT_SETTINGS]);
        app(CurrentTenant::class)->run($t, function () use ($owner) {
            TenantMember::create(['user_id' => $owner->id, 'role_id' => Role::system('owner')->id, 'status' => 'active']);
            foreach (LeadStage::DEFAULTS as $i => $s) {
                LeadStage::create([...$s, 'sort' => $i]);
            }
        });

        return $t;
    }

    private function panel(string $path): string
    {
        return $this->host('panel', "/api$path");
    }

    public function test_panel_lists_have_constant_query_count(): void
    {
        $this->actingAs($this->makeStaff());
        $owner = $this->makeUser('owner@load.test', 'Owner');

        $this->assertConstant([$this->panel('/users'), $this->panel('/tenants?type=personal')], fn ($n) => collect(range(1, $n))->each(fn () => $this->user()));
        $this->assertConstant($this->panel('/tenants?type=company'), fn ($n) => collect(range(1, $n))->each(fn () => $this->company('pt'.(++$this->seq), $owner)));
        $this->assertConstant($this->panel('/cards'), fn ($n) => collect(range(1, $n))->each(fn () => $this->makeCard(CardStatus::Active, $owner)));
        $this->assertConstant($this->panel('/domains'), fn ($n) => collect(range(1, $n))->each(fn () => TenantDomain::create([
            'tenant_id' => $owner->personal_tenant_id, 'domain' => 'k'.(++$this->seq).'.contoh.com', 'verify_token' => Str::random(32),
        ])));
        $this->assertConstant($this->panel('/orders'), fn ($n) => collect(range(1, $n))->each(fn () => Order::create([
            'number' => 'ORD-'.(++$this->seq), 'tenant_id' => $owner->personal_tenant_id, 'user_id' => $owner->id, 'subtotal' => 1000, 'total' => 1000,
        ])));
        $this->assertConstant($this->panel('/finance/invoices'), fn ($n) => collect(range(1, $n))->each(fn () => Invoice::create([
            'tenant_id' => $owner->personal_tenant_id, 'user_id' => $owner->id, 'number' => 'INV-'.(++$this->seq), 'type' => 'subscription',
            'status' => 'issued', 'lines' => [], 'subtotal' => 1000, 'total' => 1000,
        ])));
        $this->assertConstant($this->panel('/batches'), fn ($n) => collect(range(1, $n))->each(fn () => $this->makeCard()));
    }

    public function test_workspace_and_company_lists_have_constant_query_count(): void
    {
        $owner = $this->user();
        $org = $this->company('majujaya', $owner);
        $this->actingAs($owner);
        $api = fn (string $p) => $this->host('majujaya', "/api$p");

        $member = function () use ($org) {
            $u = $this->user();
            app(CurrentTenant::class)->run($org, fn () => TenantMember::create(['user_id' => $u->id, 'role_id' => Role::system('member')->id, 'status' => 'active']));

            return $u;
        };
        $this->assertConstant([$api('/company/members'), $api('/company/leads')], fn ($n) => collect(range(1, $n))->each($member));

        $stage = app(CurrentTenant::class)->run($org, fn () => LeadStage::orderBy('sort')->value('id'));
        $event = app(CurrentTenant::class)->run($org, fn () => Event::create(['created_by' => $owner->id, 'name' => 'Expo', 'starts_at' => now(), 'ends_at' => now()->addDay()]));
        $this->assertConstant($api('/leads'), fn ($n) => app(CurrentTenant::class)->run($org, fn () => collect(range(1, $n))->each(fn ($i) => Connection::create([
            'source' => 'manual', 'stage_id' => $stage, 'name' => "Lead $i", 'assignee_user_id' => $owner->id, 'captured_by_user_id' => $owner->id, 'event_id' => $event->id,
        ]))));
        $this->assertConstant($api('/company/cards'), fn ($n) => collect(range(1, $n))->each(fn () => Card::whereKey($this->makeCard()->id)->update(['tenant_id' => $org->id])));
        $this->assertConstant($api('/events'), fn ($n) => app(CurrentTenant::class)->run($org, fn () => collect(range(1, $n))->each(fn ($i) => Event::create([
            'created_by' => $owner->id, 'name' => "Event $i", 'starts_at' => now(), 'ends_at' => now()->addDay(),
        ]))));
    }
}
