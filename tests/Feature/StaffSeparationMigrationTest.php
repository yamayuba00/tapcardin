<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\StaffAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Migrasi pemisahan staf: data platform_staff lama pindah ke staff_accounts, kolom aksi panel ikut dipetakan. */
class StaffSeparationMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const PATH = 'database/migrations/2026_12_06_000100_separate_staff_accounts.php';

    public function test_legacy_staff_rows_move_to_staff_accounts_and_references_are_remapped(): void
    {
        Artisan::call('migrate:rollback', ['--path' => self::PATH, '--force' => true]);
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('staff_accounts'));

        $staffUser = $this->makeUser('lama@tapcard.test', 'Staf Lama');
        $staffUser->forceFill(['password' => 'rahasia123'])->save();
        $customer = $this->makeUser('pelanggan@example.com', 'Pelanggan');
        DB::table('platform_staff')->insert(['user_id' => $staffUser->id, 'role_id' => Role::system('finance')->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $panelBatch = DB::table('batches')->insertGetId(['public_id' => '01J00000000000000000000001', 'code' => 'B-1', 'status' => 'draft', 'chip_type' => 'ntag213', 'quantity' => 1, 'created_by' => $staffUser->id, 'created_at' => now(), 'updated_at' => now()]);
        $demoBatch = DB::table('batches')->insertGetId(['public_id' => '01J00000000000000000000002', 'code' => 'B-2', 'status' => 'draft', 'chip_type' => 'ntag213', 'quantity' => 1, 'created_by' => $customer->id, 'created_at' => now(), 'updated_at' => now()]);
        $log = fn (string $scope, int $actor) => DB::table('audit_logs')->insertGetId(['scope' => $scope, 'actor_user_id' => $actor, 'action' => 'x', 'subject_type' => 'User', 'subject_id' => 1]);
        $platformLog = $log('platform', $staffUser->id);
        $tenantLog = $log('tenant', $customer->id);

        Artisan::call('migrate', ['--path' => self::PATH, '--force' => true]);

        $staff = StaffAccount::where('email', 'lama@tapcard.test')->sole();
        $this->assertSame('Staf Lama', $staff->name);
        $this->assertSame(Role::system('finance')->id, $staff->role_id);
        $this->assertTrue(password_verify('rahasia123', $staff->password), 'password ikut pindah');
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('platform_staff'));
        $this->assertDatabaseHas('users', ['id' => $staffUser->id, 'email' => 'lama@tapcard.test']); // akun pengguna lama tetap ada

        $this->assertSame($staff->id, DB::table('batches')->where('id', $panelBatch)->value('created_by'));
        $this->assertNull(DB::table('batches')->where('id', $demoBatch)->value('created_by'), 'bukan staf → dikosongkan');
        $this->assertSame(['staff', $staff->id], array_values((array) DB::table('audit_logs')->where('id', $platformLog)->first(['actor_type', 'actor_user_id'])));
        $this->assertSame(['user', $customer->id], array_values((array) DB::table('audit_logs')->where('id', $tenantLog)->first(['actor_type', 'actor_user_id'])));

        $staff->forceFill(['last_reverified_at' => now()])->save();
        $this->postJson($this->host('panel', '/api/auth/login'), ['email' => 'lama@tapcard.test', 'password' => 'rahasia123'])->assertOk();
    }
}
