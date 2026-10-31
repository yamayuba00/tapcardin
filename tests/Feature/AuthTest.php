<?php

namespace Tests\Feature;

use App\Actions\Auth\RequestOtp;
use App\Mail\AuthCodeMail;
use App\Models\LeadStage;
use App\Models\PlatformStaff;
use App\Models\Profile;
use App\Models\TenantMember;
use App\Models\User;
use App\Support\Altcha;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Login email + password (atau Google) dengan ALTCHA; kode email hanya untuk daftar & lupa password. WS-01 workspace Personal otomatis. */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function login(string $email, string $password, bool $remember = false, ?string $altcha = null)
    {
        return $this->postJson($this->host('app', '/api/auth/login'), ['email' => $email, 'password' => $password, 'remember' => $remember, 'altcha' => $altcha ?? $this->altcha()]);
    }

    /** Kirim permintaan yang memicu email kode, kembalikan kodenya. */
    private function codeFrom(callable $send): string
    {
        $code = null;
        Mail::fake();
        $send();
        Mail::assertSent(AuthCodeMail::class, function ($m) use (&$code) {
            $code = $m->code;

            return true;
        });

        return $code;
    }

    private function register(string $email = 'Baru@Example.com', string $password = 'rahasia123')
    {
        return $this->postJson($this->host('app', '/api/auth/register'), [
            'name' => 'Pengguna Baru', 'email' => $email, 'password' => $password, 'password_confirmation' => $password, 'altcha' => $this->altcha(),
        ]);
    }

    public function test_register_sends_code_then_verify_creates_personal_workspace_and_logs_in(): void
    {
        $code = $this->codeFrom(fn () => $this->register()->assertOk()->assertJsonPath('data.email', 'baru@example.com'));
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertDatabaseMissing('users', ['email' => 'baru@example.com']);
        $this->assertGuest();

        $this->postJson($this->host('app', '/api/auth/register/verify'), ['email' => 'baru@example.com', 'code' => $code])
            ->assertCreated()->assertJsonPath('data.user.email', 'baru@example.com');
        $this->assertAuthenticated();

        $user = User::where('email', 'baru@example.com')->firstOrFail();
        $this->assertSame('Pengguna Baru', $user->name);
        $this->assertTrue(Hash::check('rahasia123', $user->password));
        $tenant = $user->personalTenant;
        $this->assertSame('personal', $tenant->type->value);
        $this->assertSame('personal_free', $tenant->plan->key);
        $this->assertTrue(TenantMember::withoutGlobalScope(TenantScope::class)->where(['tenant_id' => $tenant->id, 'user_id' => $user->id])->exists());
        $this->assertSame(6, LeadStage::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->count());
        $this->assertSame(1, Profile::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->count());

        // akun baru langsung bisa login dengan password
        $this->postJson($this->host('app', '/api/auth/logout'))->assertOk();
        $this->login('baru@example.com', 'rahasia123')->assertOk();
    }

    public function test_register_validates_password_duplicate_email_and_altcha(): void
    {
        $this->makeUser();
        $this->register('rina@example.com')->assertStatus(422)->assertJsonPath('error.fields.email.0', 'Email ini sudah terdaftar. Silakan masuk, atau gunakan "Lupa password".');
        $this->register('x@example.com', 'pendek')->assertStatus(422)->assertJsonPath('error.fields.password.0', 'Password minimal 8 karakter.');
        $this->register('x@example.com', 'hanyahuruf')->assertStatus(422)->assertJsonPath('error.fields.password.0', 'Password harus berisi huruf dan angka.');
        $this->postJson($this->host('app', '/api/auth/register'), ['name' => 'Bot', 'email' => 'bot@example.com', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123', 'altcha' => 'palsu'])
            ->assertStatus(422)->assertJsonPath('error.fields.altcha.0', fn ($m) => str_contains($m, 'anti-robot'));
    }

    public function test_wrong_code_counts_attempts_and_burns_after_five(): void
    {
        $code = $this->codeFrom(fn () => $this->register('rina@example.com')->assertOk());
        $wrong = $code === '000000' ? '111111' : '000000';
        $verify = fn ($c) => $this->postJson($this->host('app', '/api/auth/register/verify'), ['email' => 'rina@example.com', 'code' => $c]);
        for ($i = 1; $i <= 4; $i++) {
            $verify($wrong)->assertStatus(422)->assertJsonPath('error.message', 'Kode belum cocok. Sisa '.(5 - $i).' percobaan.');
        }
        $verify($wrong)->assertStatus(422)->assertJsonPath('error.message', 'Kode salah 5 kali. Minta kode baru.');
        $verify($code)->assertStatus(422)->assertJsonPath('error.message', 'Kode sudah tidak berlaku. Minta kode baru.');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'rina@example.com']);
    }

    public function test_code_request_is_throttled_per_email(): void
    {
        Mail::fake();
        for ($i = 0; $i < 3; $i++) {
            $this->register('x@example.com')->assertOk();
        }
        $this->register('x@example.com')->assertStatus(429);
    }

    public function test_login_with_password_remember_seven_days_and_generic_error(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['password' => 'rahasia123'])->save();

        $this->login('rina@example.com', 'salah123')->assertStatus(422)->assertJsonPath('error.message', 'Email atau password salah.');
        $this->login('tidakada@example.com', 'salah123')->assertStatus(422)->assertJsonPath('error.message', 'Email atau password salah.');
        $this->assertGuest();

        $res = $this->login('Rina@Example.com', 'rahasia123', remember: true)->assertOk();
        $this->assertAuthenticatedAs($user);
        $cookie = collect($res->headers->getCookies())->first(fn ($c) => str_starts_with($c->getName(), 'remember_web_'));
        $this->assertNotNull($cookie, 'cookie "ingat saya" dibuat');
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $cookie->getExpiresTime(), 120, 'berlaku 7 hari');
    }

    public function test_login_without_remember_sets_no_remember_cookie(): void
    {
        $this->makeUser()->forceFill(['password' => 'rahasia123'])->save();
        $res = $this->login('rina@example.com', 'rahasia123')->assertOk();
        $this->assertNull(collect($res->headers->getCookies())->first(fn ($c) => str_starts_with($c->getName(), 'remember_web_')));
    }

    public function test_login_requires_valid_single_use_altcha(): void
    {
        $this->makeUser()->forceFill(['password' => 'rahasia123'])->save();
        $this->login('rina@example.com', 'rahasia123', altcha: 'bukan-payload')->assertStatus(422)->assertJsonPath('error.fields.altcha.0', fn ($m) => str_contains($m, 'anti-robot'));

        $payload = $this->altcha();
        $this->login('rina@example.com', 'salah123', altcha: $payload)->assertStatus(422);
        $this->login('rina@example.com', 'rahasia123', altcha: $payload)->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['altcha']]]);
        $this->assertGuest();

        $this->getJson($this->host('app', '/api/auth/altcha'))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonStructure(['parameters' => ['algorithm', 'cost', 'expiresAt', 'keyLength', 'keyPrefix', 'nonce', 'salt'], 'signature'])->assertJsonPath('parameters.algorithm', 'SHA-256');
    }

    public function test_login_is_throttled_and_guides_accounts_without_password(): void
    {
        $user = $this->makeUser(); // akun lama: belum punya password
        $this->login('rina@example.com', 'apa1234')->assertStatus(422)->assertJsonPath('error.message', 'Akun ini belum punya password. Buat password lewat "Lupa password".');
        $user->forceFill(['google_id' => 'g-1'])->save();
        $this->login('rina@example.com', 'apa1234')->assertStatus(422)->assertJsonPath('error.message', fn ($m) => str_contains($m, 'Google'));

        $user->forceFill(['password' => 'rahasia123'])->save();
        for ($i = 0; $i < 5; $i++) {
            $this->login('rina@example.com', 'salah123')->assertStatus(422);
        }
        $this->login('rina@example.com', 'rahasia123')->assertStatus(429);
    }

    public function test_forgot_password_resets_and_logs_out_other_sessions(): void
    {
        $user = $this->makeUser();
        $this->postJson($this->host('app', '/api/auth/password/forgot'), ['email' => 'tidakada@example.com', 'altcha' => $this->altcha()])
            ->assertOk()->assertJsonPath('data.sent', true); // jawaban sama: tidak membocorkan email terdaftar

        DB::table('sessions')->insert(['id' => 'sesi-lain', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $code = $this->codeFrom(fn () => $this->postJson($this->host('app', '/api/auth/password/forgot'), ['email' => 'Rina@example.com', 'altcha' => $this->altcha()])->assertOk());

        $reset = fn ($c, $pw = 'baru12345') => $this->postJson($this->host('app', '/api/auth/password/reset'), ['email' => 'rina@example.com', 'code' => $c, 'password' => $pw, 'password_confirmation' => $pw]);
        $reset($code, 'pendek')->assertStatus(422);
        $reset($code)->assertOk();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Hash::check('baru12345', $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'sesi-lain']);
        $reset($code)->assertStatus(422); // kode sekali pakai
    }

    public function test_old_otp_login_endpoints_are_gone(): void
    {
        $this->postJson($this->host('app', '/api/auth/otp/request'), ['email' => 'rina@example.com'])->assertNotFound();
        $this->postJson($this->host('app', '/api/auth/otp/verify'), ['email' => 'rina@example.com', 'code' => '123456'])->assertNotFound();
    }

    public function test_blocked_user_cannot_log_in(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['password' => 'rahasia123', 'blocked_at' => now()])->save();
        $this->login('rina@example.com', 'rahasia123')->assertStatus(422);
        $this->assertGuest();
    }

    private function adminLogin(string $email, string $password)
    {
        return $this->postJson($this->host('panel', '/api/auth/login'), compact('email', 'password'));
    }

    public function test_admin_login_with_email_and_password(): void
    {
        $owner = User::where('email', 'owner@tapcard.test')->firstOrFail();
        $this->assertNotNull($owner->password, 'seeder memberi password ke Pemilik Platform');
        $owner->forceFill(['password' => 'rahasia123'])->save();

        $this->adminLogin('Owner@TapCard.test', 'rahasia123')->assertOk()->assertJsonPath('data.user.email', 'owner@tapcard.test');
        $this->getJson($this->host('panel', '/api/dashboard'))->assertOk()->assertJsonStructure(['data' => ['users', 'cards', 'series']]);
        $this->assertNotNull(PlatformStaff::where('user_id', $owner->id)->value('last_reverified_at'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.logged_in']);
    }

    public function test_admin_login_rejects_wrong_password_and_non_staff_with_same_message(): void
    {
        User::where('email', 'owner@tapcard.test')->firstOrFail()->forceFill(['password' => 'rahasia123'])->save();
        $user = $this->makeUser();
        $user->forceFill(['password' => 'rahasia123'])->save(); // punya password tapi bukan staf

        foreach ([['owner@tapcard.test', 'salah'], ['rina@example.com', 'rahasia123'], ['tidakada@example.com', 'x']] as [$email, $pw]) {
            $this->adminLogin($email, $pw)->assertStatus(422)->assertJsonPath('error.message', 'Email atau password salah.');
        }
        $this->assertGuest();
    }

    public function test_admin_login_is_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->adminLogin('owner@tapcard.test', 'salah')->assertStatus(422);
        }
        $this->adminLogin('owner@tapcard.test', 'salah')->assertStatus(429);
    }

    public function test_staff_can_change_password(): void
    {
        $owner = User::where('email', 'owner@tapcard.test')->firstOrFail();
        $owner->forceFill(['password' => 'lama12345'])->save();
        $this->adminLogin('owner@tapcard.test', 'lama12345')->assertOk();

        $url = $this->host('panel', '/api/me/password');
        $this->putJson($url, ['current_password' => 'keliru', 'password' => 'baru12345', 'password_confirmation' => 'baru12345'])
            ->assertStatus(422)->assertJsonPath('error.fields.current_password.0', 'Password saat ini salah.');
        $this->putJson($url, ['current_password' => 'lama12345', 'password' => 'pendek', 'password_confirmation' => 'pendek'])->assertStatus(422);
        $this->putJson($url, ['current_password' => 'lama12345', 'password' => 'baru12345', 'password_confirmation' => 'baru12345'])->assertOk();
        $this->assertTrue(Hash::check('baru12345', $owner->fresh()->password));
    }

    public function test_dev_code_only_works_in_local_environment(): void
    {
        config(['tapcard.dev_login_code' => '123456']);

        // APP_ENV=testing → kode acak seperti produksi
        $this->assertNotSame('123456', $this->codeFrom(fn () => $this->register('a@example.com')->assertOk()));

        // APP_ENV=local → kode tetap (action dipanggil langsung; env local di test menyalakan cek CSRF)
        $this->app['env'] = 'local';
        Mail::fake();
        app(RequestOtp::class)->handle('b@example.com', '127.0.0.1', 'register', ['name' => 'B', 'password_hash' => Hash::make('rahasia123')]);
        Mail::assertSent(AuthCodeMail::class, fn ($m) => $m->code === '123456');
        $this->app['env'] = 'testing';
        $this->postJson($this->host('app', '/api/auth/register/verify'), ['email' => 'b@example.com', 'code' => '123456'])->assertCreated();
        $this->assertAuthenticated();
    }

    public function test_altcha_rejects_test_mode_tampered_reused_and_expired_payloads(): void
    {
        $this->assertFalse(Altcha::valid(base64_encode(json_encode(['challenge' => null, 'solution' => null, 'test' => true]))), 'mode test widget');

        $ok = json_decode(base64_decode($this->altcha()), true);
        $tampered = $ok;
        $tampered['challenge']['parameters']['expiresAt'] += 3600; // perpanjang masa berlaku sendiri
        $this->assertFalse(Altcha::valid(base64_encode(json_encode($tampered))), 'parameter diubah → tanda tangan tidak cocok');

        $wrong = $ok;
        $wrong['solution']['counter']++;
        $this->assertFalse(Altcha::valid(base64_encode(json_encode($wrong))), 'solusi salah');

        $this->assertTrue(Altcha::valid(base64_encode(json_encode($ok))));
        $this->assertFalse(Altcha::valid(base64_encode(json_encode($ok))), 'sekali pakai');

        $late = $this->altcha();
        $this->travel(11)->minutes();
        $this->assertFalse(Altcha::valid($late), 'kedaluwarsa setelah 10 menit');
    }
}
