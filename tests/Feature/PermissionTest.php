<?php

namespace Tests\Feature;

use App\Authz\PermissionResolver;
use App\Enums\LeadSource;
use App\Enums\TenantType;
use App\Models\Connection;
use App\Models\LeadStage;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** TRD §15 — matriks izin PRD §5.2–§5.3 + cakupan Tim/Sendiri pada daftar lead. */
class PermissionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Tenant $company;

    /** @var array<string, User> */
    private array $members = [];

    private function company(string $plan = 'business'): Tenant
    {
        $owner = $this->makeUser('owner@majujaya.id', 'Owner');
        $this->company = Tenant::create([
            'type' => TenantType::Company, 'name' => 'PT Maju Jaya', 'slug' => 'majujaya', 'owner_user_id' => $owner->id,
            'status' => 'active', 'plan_id' => Plan::where('key', $plan)->value('id'), 'settings' => Tenant::DEFAULT_SETTINGS,
        ]);
        app(CurrentTenant::class)->run($this->company, function () use ($owner) {
            foreach (LeadStage::DEFAULTS as $i => $s) {
                LeadStage::create([...$s, 'sort' => $i]);
            }
            $this->members['owner'] = $owner;
            $this->join($owner, 'owner');
        });

        return $this->company;
    }

    private function join(User $user, string $role): void
    {
        app(CurrentTenant::class)->run($this->company, fn () => TenantMember::create([
            'user_id' => $user->id, 'role_id' => Role::system($role)->id, 'status' => 'active',
        ]));
    }

    private function perms(User $user): array
    {
        return app(PermissionResolver::class)->for($user, $this->company)['perms'];
    }

    #[DataProvider('matrix')]
    public function test_company_role_matrix(string $role, string $permission, bool $expected): void
    {
        $this->company();
        $user = $role === 'owner' ? $this->members['owner'] : $this->makeUser("$role@majujaya.id");
        if ($role !== 'owner') {
            $this->join($user, $role);
        }
        $this->assertSame($expected, isset($this->perms($user)[$permission]), "$role × $permission");
    }

    public static function matrix(): array
    {
        return [
            // PRD §5.3 (contoh baris penting)
            ['owner', 'workspace.domain', true], ['admin', 'workspace.domain', false],
            ['owner', 'workspace.delete', true], ['admin', 'workspace.delete', false],
            ['admin', 'members.invite', true], ['manager', 'members.invite', false],
            ['admin', 'billing.view', true], ['admin', 'billing.manage', false], ['billing', 'billing.manage', true],
            ['billing', 'leads.view.own', false], ['billing', 'orders.create', true],
            ['manager', 'leads.view.team', true], ['manager', 'leads.view.all', false], ['manager', 'leads.delete', false],
            ['member', 'leads.view.own', true], ['member', 'leads.view.team', false], ['member', 'leads.assign.team', false],
            ['event_staff', 'leads.view.event', true], ['event_staff', 'events.checkin', true],
            ['viewer', 'leads.view.all', true], ['viewer', 'leads.edit.all', false], ['viewer', 'cards.manage.own', false],
            ['admin', 'leads.delete', true], ['member', 'leads.delete', false],
        ];
    }

    public function test_plan_features_strip_permissions(): void
    {
        $this->company('team'); // Team: tanpa webhook & custom field
        $this->assertArrayNotHasKey('integrations.manage', $this->perms($this->members['owner']));
        $this->assertArrayNotHasKey('fields.manage', $this->perms($this->members['owner']));
        $this->assertArrayHasKey('teams.manage', $this->perms($this->members['owner']));
    }

    public function test_permission_cache_is_invalidated_by_version_bump(): void
    {
        $this->company();
        $user = $this->makeUser('x@majujaya.id');
        $this->join($user, 'member');
        $this->assertArrayNotHasKey('leads.view.all', $this->perms($user));

        app(CurrentTenant::class)->run($this->company, fn () => TenantMember::where('user_id', $user->id)
            ->update(['role_id' => Role::system('viewer')->id]));
        $this->assertArrayNotHasKey('leads.view.all', $this->perms($user), 'masih ter-cache sebelum bump');
        PermissionResolver::bump($this->company->id);
        $this->assertArrayHasKey('leads.view.all', $this->perms($user));
    }

    public function test_lead_visibility_scopes(): void
    {
        $this->company();
        [$manager, $sales1, $sales2, $other] = [
            $this->makeUser('mgr@majujaya.id'), $this->makeUser('s1@majujaya.id'), $this->makeUser('s2@majujaya.id'), $this->makeUser('s3@majujaya.id'),
        ];
        $this->join($manager, 'manager');
        foreach ([$sales1, $sales2, $other] as $u) {
            $this->join($u, 'member');
        }

        app(CurrentTenant::class)->run($this->company, function () use ($manager, $sales1, $sales2, $other) {
            $team = Team::create(['name' => 'Jakarta']);
            TeamMember::create(['team_id' => $team->id, 'user_id' => $manager->id, 'is_manager' => true]);
            TeamMember::create(['team_id' => $team->id, 'user_id' => $sales1->id]);
            $stage = LeadStage::where('is_default', true)->value('id');
            $mk = fn (User $u, ?int $teamId, string $name) => Connection::create([
                'source' => LeadSource::Manual, 'captured_by_user_id' => $u->id, 'assignee_user_id' => $u->id,
                'team_id' => $teamId, 'stage_id' => $stage, 'name' => $name,
            ]);
            $mk($sales1, $team->id, 'Lead tim');
            $mk($sales2, null, 'Lead s2');
            $mk($other, null, 'Lead lain');
        });

        $visible = fn (User $u) => app(CurrentTenant::class)->run($this->company, fn () => Connection::query()
            ->visibleTo($u, app(PermissionResolver::class)->for($u, $this->company))->orderBy('name')->pluck('name')->all());

        $this->assertSame(['Lead tim'], $visible($manager));
        $this->assertSame(['Lead s2'], $visible($sales2));
        $this->assertSame(['Lead lain', 'Lead s2', 'Lead tim'], $visible($this->members['owner']));
    }

    public function test_personal_owner_has_full_access_to_own_workspace(): void
    {
        $u = $this->makeUser();
        $p = app(PermissionResolver::class)->for($u, $u->personalTenant)['perms'];
        foreach (['leads.view.all', 'leads.delete', 'leads.export.all', 'analytics.view.all', 'profiles.edit.own'] as $perm) {
            $this->assertArrayHasKey($perm, $p);
        }
        $this->assertArrayNotHasKey('members.invite', $p);
    }
}
