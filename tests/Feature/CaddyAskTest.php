<?php

namespace Tests\Feature;

use App\Enums\TenantStatus;
use App\Enums\TenantType;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Caddy on-demand TLS: sertifikat hanya untuk host yang berhak; permintaan dari luar loopback ditolak. */
class CaddyAskTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function ask(string $host, string $ip = '127.0.0.1', array $headers = [])
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->get('/internal/caddy-ask?domain='.$host, $headers);
    }

    private function company(string $slug, string $plan = 'business', TenantStatus $status = TenantStatus::Active): Tenant
    {
        $owner = $this->makeUser('owner-'.$slug.'@example.com');

        return Tenant::create([
            'type' => TenantType::Company, 'name' => ucfirst($slug), 'slug' => $slug, 'owner_user_id' => $owner->id,
            'status' => $status, 'plan_id' => Plan::where('key', $plan)->value('id'), 'settings' => Tenant::DEFAULT_SETTINGS,
        ]);
    }

    private function customDomain(Tenant $tenant, string $domain, string $status = 'active'): void
    {
        TenantDomain::create(['tenant_id' => $tenant->id, 'domain' => $domain, 'verify_token' => 'tok-'.$domain, 'status' => $status,
            'verified_at' => $status === 'active' ? now() : null]);
    }

    public function test_base_domain_and_reserved_subdomains_are_allowed(): void
    {
        $base = config('tapcard.base_domain');
        $this->ask($base)->assertOk();
        foreach (['tap', 'app', 'panel', 'status', 'wbh', 'www'] as $sub) {
            $this->ask("{$sub}.{$base}")->assertOk();
        }
    }

    public function test_unknown_random_subdomain_gets_no_certificate(): void
    {
        $this->ask('acak-xyz.'.config('tapcard.base_domain'))->assertNotFound();
        $this->ask('evil.example.com')->assertNotFound();
        $this->ask('')->assertNotFound();
    }

    public function test_company_slug_is_allowed_unless_suspended(): void
    {
        $base = config('tapcard.base_domain');
        $this->company('majujaya');
        $this->ask("majujaya.{$base}")->assertOk();

        $this->company('bekunjaya', 'business', TenantStatus::Suspended);
        $this->ask("bekunjaya.{$base}")->assertNotFound();
    }

    public function test_active_custom_domain_on_plan_with_feature_is_allowed(): void
    {
        $this->customDomain($this->company('acme'), 'kartu.acme.com');
        $this->ask('kartu.acme.com')->assertOk();
    }

    public function test_custom_domain_on_plan_without_feature_is_refused(): void
    {
        $this->customDomain($this->company('kecil', 'starter'), 'kartu.kecil.com');
        $this->ask('kartu.kecil.com')->assertNotFound();
    }

    public function test_pending_custom_domain_is_refused(): void
    {
        $this->customDomain($this->company('tunggu'), 'kartu.tunggu.com', 'pending');
        $this->ask('kartu.tunggu.com')->assertNotFound();
    }

    public function test_requests_from_outside_loopback_or_with_forwarded_header_are_refused(): void
    {
        $base = config('tapcard.base_domain');
        $this->ask("majujaya.{$base}", '203.0.113.9')->assertNotFound();
        $this->ask("tap.{$base}", '127.0.0.1', ['X-Forwarded-For' => '203.0.113.9'])->assertNotFound();
    }

    public function test_behind_caddy_the_request_scheme_is_https(): void
    {
        Route::get('/tmp-scheme-test', fn (Request $r) => $r->getScheme());
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->get('/tmp-scheme-test', ['X-Forwarded-Proto' => 'https'])->assertOk()->assertSee('https', false);
    }
}
