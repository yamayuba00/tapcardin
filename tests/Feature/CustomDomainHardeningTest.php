<?php

namespace Tests\Feature;

use App\Authz\PlanFeatures;
use App\Jobs\VerifyDomain;
use App\Models\Notification;
use App\Models\TenantDomain;
use App\Support\Dns\DnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Domain kustom: gerbang paket, verifikasi di antrean, dan pemeriksaan ulang berkala. */
class CustomDomainHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function panel(string $method, string $path, array $data = [])
    {
        return $this->json($method, $this->host('panel', "/api$path"), $data);
    }

    private function resolverWith(array $records): void
    {
        $this->app->instance(DnsResolver::class, new class($records) extends DnsResolver
        {
            public function __construct(private array $records) {}

            public function txt(string $name): array
            {
                return $this->records[$name] ?? [];
            }
        });
    }

    private function freeUserWithDomain(string $status = 'pending'): array
    {
        $rina = $this->makeUser();
        $tenant = $rina->personalTenant;
        $d = TenantDomain::create(['tenant_id' => $tenant->id, 'domain' => 'kartu.acme.com', 'verify_token' => str_repeat('a', 32), 'status' => $status, 'verified_at' => $status === 'active' ? now() : null]);

        return [$rina, $tenant, $d];
    }

    public function test_plan_without_custom_domain_cannot_verify_or_activate(): void
    {
        $this->actingAs($this->makeStaff('platform_owner', 'domainowner@tapcard.test', 'Owner'));
        [, $tenant, $d] = $this->freeUserWithDomain();
        $this->resolverWith([$d->txtName() => [$d->txtValue()]]);

        $this->panel('POST', "/domains/{$d->public_id}/verify")->assertStatus(402)->assertJsonPath('error.code', 'plan_required');
        $this->assertSame('pending', $d->fresh()->status);

        $this->panel('PATCH', "/domains/{$d->public_id}", ['status' => 'active'])->assertStatus(402)->assertJsonPath('error.code', 'plan_required');
        $this->assertSame('pending', $d->fresh()->status);
    }

    public function test_downgrade_disables_active_domain_and_routing_stops(): void
    {
        [$rina, $tenant, $d] = $this->freeUserWithDomain('active');
        $tenant->update(['limit_overrides' => ['features' => ['custom_domain' => true]]]);
        PlanFeatures::forget($tenant);
        $this->actingAs($rina);
        $this->getJson('http://kartu.acme.com/api/billing')->assertOk();

        $tenant->fresh()->update(['limit_overrides' => []]);

        $d->refresh();
        $this->assertSame('disabled', $d->status);
        $this->assertStringContainsString('Paket tidak mencakup', $d->last_error);
        $this->getJson('http://kartu.acme.com/api/billing')->assertNotFound();
    }

    public function test_verify_is_queued_and_the_job_updates_the_row(): void
    {
        $this->actingAs($this->makeStaff('platform_owner', 'domainowner@tapcard.test', 'Owner'));
        [, $tenant, $d] = $this->freeUserWithDomain();
        $tenant->update(['limit_overrides' => ['features' => ['custom_domain' => true]]]);
        PlanFeatures::forget($tenant);
        Queue::fake();

        $this->panel('POST', "/domains/{$d->public_id}/verify")->assertStatus(202);
        Queue::assertPushed(VerifyDomain::class, fn ($j) => $j->domainId === $d->id);

        $this->resolverWith([$d->txtName() => [$d->txtValue()]]);
        (new VerifyDomain($d->id))->handle(app(DnsResolver::class));
        $this->assertSame('active', $d->fresh()->status);
        $this->assertNull(Cache::get(VerifyDomain::checkingKey($d->id)));
    }

    public function test_recheck_sets_pending_after_two_misses_and_notifies_once(): void
    {
        [$rina, $tenant, $d] = $this->freeUserWithDomain('active');
        $this->resolverWith([]);

        $this->artisan('tapcard:domains-recheck')->assertSuccessful();
        $this->assertSame('active', $d->fresh()->status, 'miss pertama: masih aktif');

        $this->artisan('tapcard:domains-recheck')->assertSuccessful();
        $d->refresh();
        $this->assertSame('pending', $d->status);
        $this->assertSame('TXT verifikasi hilang', $d->last_error);

        $this->artisan('tapcard:domains-recheck')->assertSuccessful();
        $this->assertSame(1, Notification::where('user_id', $rina->id)->where('type', 'domain_unverified')->count());
    }
}
