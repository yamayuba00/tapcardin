<?php

namespace Tests\Feature;

use App\Enums\TenantType;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Pemilih akun & karyawan dicari di server: hasil di luar 100 data pertama tetap ketemu, cakupan & isolasi tetap. */
class PickerSearchTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /** Akun ringan tanpa workspace pribadi — cukup untuk data pemilih. */
    private function bulkUsers(int $n, string $prefix): array
    {
        $ids = [];
        for ($i = 1; $i <= $n; $i++) {
            $ids[] = User::create(['email' => sprintf('%s%03d@contoh.id', $prefix, $i), 'name' => sprintf('Anggota %03d', $i),
                'locale' => 'id', 'timezone' => 'Asia/Jakarta', 'notification_prefs' => []])->id;
        }

        return $ids;
    }

    private function company(string $slug, User $owner): Tenant
    {
        return Tenant::create(['type' => TenantType::Company, 'name' => ucfirst($slug), 'slug' => $slug, 'owner_user_id' => $owner->id,
            'status' => 'active', 'plan_id' => Plan::where('key', 'business')->value('id'), 'settings' => Tenant::DEFAULT_SETTINGS]);
    }

    public function test_panel_owner_picker_searches_all_active_accounts(): void
    {
        $this->bulkUsers(120, 'akun');
        $blocked = User::where('email', 'akun119@contoh.id')->firstOrFail();
        $blocked->forceFill(['blocked_at' => now()])->save();
        $staff = $this->makeStaff();
        $this->actingAs($staff);
        $get = fn (array $q) => $this->json('GET', $this->host('panel', '/api/users?'.http_build_query($q)))->assertOk()->json('data');

        $this->assertSame(['akun120@contoh.id'], array_column($get(['q' => 'akun120', 'status' => 'active', 'per_page' => 20]), 'email'));
        $this->assertSame([], $get(['q' => 'akun119', 'status' => 'active', 'per_page' => 20]), 'akun diblokir tidak bisa dipilih');
        $this->assertCount(20, $get(['status' => 'active', 'per_page' => 20, 'sort' => 'name']));
        $this->assertSame([], $get(['q' => $staff->email, 'status' => 'active']), 'akun staf panel bukan pengguna app');
    }

    public function test_company_and_lead_assignee_pickers_search_beyond_first_page_within_scope(): void
    {
        $owner = $this->makeUser('owner@majujaya.id', 'Owner MJ');
        $manager = $this->makeUser('dewi@majujaya.id', 'Dewi Manager');
        $org = $this->company('majujaya', $owner);
        $ids = $this->bulkUsers(110, 'staf');
        $other = $this->company('lain', $this->makeUser('owner@lain.id', 'Owner Lain'));
        $foreign = $this->bulkUsers(1, 'asing')[0];

        app(CurrentTenant::class)->run($org, function () use ($owner, $manager, $ids) {
            foreach ([[$owner->id, 'owner'], [$manager->id, 'manager']] as [$u, $role]) {
                TenantMember::create(['user_id' => $u, 'role_id' => Role::system($role)->id, 'status' => 'active']);
            }
            foreach ($ids as $u) {
                TenantMember::create(['user_id' => $u, 'role_id' => Role::system('member')->id, 'status' => 'active']);
            }
            $team = Team::create(['name' => 'Sales']);
            TeamMember::create(['team_id' => $team->id, 'user_id' => $manager->id, 'is_manager' => true]);
            TeamMember::create(['team_id' => $team->id, 'user_id' => $ids[109], 'is_manager' => false]); // staf110
        });
        app(CurrentTenant::class)->run($other, fn () => TenantMember::create(['user_id' => $foreign, 'role_id' => Role::system('member')->id, 'status' => 'active']));

        foreach (['/company/cards/assignees', '/leads/assignees'] as $path) {
            $get = fn (array $q) => array_column($this->json('GET', $this->host('majujaya', "/api$path?".http_build_query($q)))->assertOk()->json('data'), 'email');

            $this->actingAs($owner);
            $this->assertSame(['staf110@contoh.id'], $get(['q' => 'staf110']), "$path: anggota ke-110 ketemu lewat pencarian");
            $this->assertCount(20, $get([]), "$path: dibatasi 20 bawaan");
            $this->assertCount(50, $get(['limit' => 500]), "$path: batas maksimum 50");
            $this->assertSame([], $get(['q' => 'asing']), "$path: anggota workspace lain tidak ikut");
            $this->assertSame([], $get(['q' => '%']), "$path: wildcard diperlakukan sebagai teks");

            $this->actingAs($manager);
            $this->assertSame(['staf110@contoh.id'], $get(['q' => 'staf110']), "$path: Manager melihat anggota timnya");
            $this->assertSame([], $get(['q' => 'staf001']), "$path: Manager tidak melihat anggota di luar timnya");
        }
    }

    public function test_assignee_picker_has_no_n_plus_one(): void
    {
        $owner = $this->makeUser('owner@majujaya.id', 'Owner MJ');
        $org = $this->company('majujaya', $owner);
        $ids = $this->bulkUsers(40, 'staf');
        app(CurrentTenant::class)->run($org, function () use ($owner, $ids) {
            TenantMember::create(['user_id' => $owner->id, 'role_id' => Role::system('owner')->id, 'status' => 'active']);
            foreach ($ids as $u) {
                TenantMember::create(['user_id' => $u, 'role_id' => Role::system('member')->id, 'status' => 'active']);
            }
        });
        $this->actingAs($owner);
        $count = function (int $limit) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->json('GET', $this->host('majujaya', "/api/company/cards/assignees?limit=$limit"))->assertOk();

            return count(DB::getQueryLog());
        };
        $count(1); // pemanasan: cache sesi, workspace, izin
        $this->assertSame($count(5), $count(40));
    }
}
