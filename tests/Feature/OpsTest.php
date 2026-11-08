<?php

namespace Tests\Feature;

use App\Console\Commands\Health;
use App\Mail\NotificationMail;
use App\Models\PlatformStaff;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/** Operasional: lupa password staf panel, alarm email, dan backup database ke S3. */
class OpsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function staff(string $email = 'dina@tapcard.test'): User
    {
        $u = $this->makeUser($email, 'Dina');
        $u->forceFill(['password' => 'lama12345'])->save();
        PlatformStaff::create(['user_id' => $u->id, 'role_id' => Role::system('platform_owner')->id, 'status' => 'active', 'last_reverified_at' => now()]);

        return $u;
    }

    /** @return list<string> */
    private function sent(?string $to = null): array
    {
        return Mail::sent(NotificationMail::class, fn ($m) => ! $to || $m->hasTo($to))->map->type->values()->all();
    }

    // ── lupa password ─────────────────────────────────────────────

    public function test_staff_resets_password_through_emailed_link_once(): void
    {
        $dina = $this->staff();
        $this->makeUser('bukan.staf@example.com');
        DB::table('sessions')->insert(['id' => 'lama', 'user_id' => $dina->id, 'payload' => '', 'last_activity' => time()]);

        $panel = fn (string $path, array $body) => $this->postJson($this->host('panel', $path), $body);
        $same = $panel('/api/auth/forgot-password', ['email' => 'bukan.staf@example.com'])->assertOk()->json('data.message');
        $this->assertSame([], $this->sent(), 'bukan staf: tidak ada email');
        $this->assertSame($same, $panel('/api/auth/forgot-password', ['email' => 'DINA@tapcard.test'])->assertOk()->json('data.message'), 'jawaban sama');
        $panel('/api/auth/forgot-password', ['email' => 'dina@tapcard.test']); // < 60 detik: tidak dikirim ulang
        $this->assertSame(['password_reset'], $this->sent('dina@tapcard.test'));

        $mail = Mail::sent(NotificationMail::class)->first();
        $url = $mail->body()['action']['url'];
        $this->assertStringStartsWith('http://panel.localhost/reset-password?token=', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $q);

        $panel('/api/auth/reset-password', ['email' => $q['email'], 'token' => 'salah', 'password' => 'baru12345', 'password_confirmation' => 'baru12345'])->assertStatus(422)->assertJsonPath('error.fields.token.0', fn ($m) => str_contains($m, 'tidak berlaku'));
        $panel('/api/auth/reset-password', ['email' => $q['email'], 'token' => $q['token'], 'password' => 'pendek', 'password_confirmation' => 'pendek'])->assertStatus(422);
        $panel('/api/auth/reset-password', ['email' => $q['email'], 'token' => $q['token'], 'password' => 'baru12345', 'password_confirmation' => 'baru12345'])->assertOk();

        $this->assertTrue(Hash::check('baru12345', $dina->fresh()->password));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $dina->id)->count(), 'sesi lama berakhir');
        $panel('/api/auth/reset-password', ['email' => $q['email'], 'token' => $q['token'], 'password' => 'lagi12345', 'password_confirmation' => 'lagi12345'])->assertStatus(422);
        $panel('/api/auth/login', ['email' => 'dina@tapcard.test', 'password' => 'baru12345'])->assertOk();
    }

    public function test_reset_token_expires_after_60_minutes(): void
    {
        $this->staff();
        $this->postJson($this->host('panel', '/api/auth/forgot-password'), ['email' => 'dina@tapcard.test']);
        parse_str(parse_url(Mail::sent(NotificationMail::class)->first()->body()['action']['url'], PHP_URL_QUERY), $q);
        $this->travel(61)->minutes();
        $this->postJson($this->host('panel', '/api/auth/reset-password'), $q + ['password' => 'baru12345', 'password_confirmation' => 'baru12345'])->assertStatus(422);
    }

    // ── alarm ─────────────────────────────────────────────────────

    public function test_alert_emails_owner_after_two_failing_runs_then_reminds_and_reports_recovery(): void
    {
        $this->staff('owner2@tapcard.test');
        config(['tapcard.alert.emails' => ['ops@tapcard.test'], 'tapcard.alert.errors_15m' => 3]);
        foreach (range(1, 3) as $_) {
            report(new RuntimeException('DB timeout'));
        }
        $check = collect(Health::collect()['checks'])->firstWhere('label', 'Error server 15 menit');
        $this->assertFalse($check['ok']);
        $this->assertStringContainsString('RuntimeException — DB timeout', $check['value']);

        $this->artisan('tapcard:alert');
        $this->assertSame([], $this->sent(), 'sekali gagal: tunggu konfirmasi');
        $this->travel(5)->minutes();
        $this->artisan('tapcard:alert');
        $this->assertSame(['system_alert'], $this->sent('ops@tapcard.test'));
        $this->assertSame(['system_alert'], $this->sent('owner2@tapcard.test'));
        $mail = Mail::sent(NotificationMail::class)->first();
        $this->assertMatchesRegularExpression('#^http://panel\.localhost(:\d+)?/system$#', $mail->body()['action']['url']);
        $mail->assertSeeInHtml('Error server 15 menit');

        foreach (range(1, 3) as $_) {
            report(new RuntimeException('masih'));
        }
        $this->artisan('tapcard:alert');
        $this->assertCount(3, $this->sent(), 'masalah sama: tidak diulang (3 penerima: ALERT_EMAILS + 2 pemilik platform)');

        $this->travel(6)->hours(); // masih bermasalah 6 jam kemudian → pengingat
        foreach (range(1, 3) as $_) {
            report(new RuntimeException('masih juga'));
        }
        $this->artisan('tapcard:alert');
        $this->assertSame(['system_alert', 'system_alert'], $this->sent('ops@tapcard.test'));
        Mail::sent(NotificationMail::class)->last()->assertSeeInHtml('Pengingat: masalah ini masih berlangsung');

        $this->travel(20)->minutes(); // jendela 15 menit kosong → pulih
        $this->artisan('tapcard:alert');
        $this->assertSame(['system_alert', 'system_alert', 'system_recovered'], $this->sent('ops@tapcard.test'));
    }

    public function test_capacity_hints_never_trigger_alerts(): void
    {
        config(['tapcard.alert.emails' => ['ops@tapcard.test']]);
        $checks = collect(Health::collect()['checks']);
        $this->assertTrue($checks->firstWhere('label', 'Tap 24 jam')['advisory']);
        $this->artisan('tapcard:alert');
        $this->artisan('tapcard:alert');
        $this->assertSame([], $this->sent());
    }

    // ── backup ────────────────────────────────────────────────────

    public function test_backup_uploads_dump_prunes_old_copies_and_feeds_health(): void
    {
        Storage::fake('backups');
        config(['tapcard.backup.disk' => 'backups', 'tapcard.backup.keep_days' => 30, 'tapcard.backup.db_port' => 5433]);
        $old = 'tapcard-'.now('Asia/Jakarta')->subDays(40)->format('Ymd').'-021500.dump';
        $recent = 'tapcard-'.now('Asia/Jakarta')->subDays(3)->format('Ymd').'-021500.dump';
        Storage::disk('backups')->put($old, 'x');
        Storage::disk('backups')->put($recent, 'x');

        Process::fake(function (PendingProcess $p) {
            $file = substr(collect($p->command)->first(fn ($a) => str_starts_with($a, '--file=')), 7);
            file_put_contents($file, 'PGDMP-isi-backup');

            return Process::result();
        });
        $this->artisan('tapcard:backup')->assertSuccessful();

        Process::assertRan(fn (PendingProcess $p) => $p->command[0] === 'pg_dump' && in_array('--port=5433', $p->command, true)
            && in_array('--format=custom', $p->command, true) && ($p->environment['PGPASSWORD'] ?? null) === 'secret');
        $files = Storage::disk('backups')->files();
        $this->assertNotContains($old, $files, 'lebih dari 30 hari dihapus');
        $this->assertContains($recent, $files);
        $this->assertCount(2, $files);
        $this->assertSame([], glob(storage_path('app/tapcard-*.dump')), 'file sementara dibersihkan');
        $this->assertTrue(collect(Health::collect()['checks'])->firstWhere('label', 'Backup database terakhir')['ok']);

        $this->travel(27)->hours();
        $this->assertFalse(collect(Health::collect()['checks'])->firstWhere('label', 'Backup database terakhir')['ok'], 'lebih dari 26 jam → alarm');
    }

    public function test_failed_dump_reports_error_and_keeps_old_status(): void
    {
        Storage::fake('backups');
        config(['tapcard.backup.disk' => 'backups']);
        Process::fake(fn () => Process::result(errorOutput: 'pg_dump: error: server version mismatch', exitCode: 1));
        $this->artisan('tapcard:backup')->assertFailed()->expectsOutputToContain('server version mismatch');
        $this->assertNull(Cache::get('backup:last'));
        $this->assertSame([], Storage::disk('backups')->files());
    }
}
