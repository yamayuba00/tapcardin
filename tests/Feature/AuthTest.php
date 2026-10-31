<?php

namespace Tests\Feature;

use App\Actions\Auth\RequestOtp;
use App\Mail\LoginCodeMail;
use App\Models\LeadStage;
use App\Models\PlatformStaff;
use App\Models\Profile;
use App\Models\TenantMember;
use App\Models\User;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** TRD §5.1 — email OTP; WS-01 workspace Personal otomatis. */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function requestCode(string $email, string $host = 'app'): string
    {
        $code = null;
        Mail::fake();
        $this->postJson($this->host($host, '/api/auth/otp/request'), ['email' => $email])->assertOk();
        Mail::assertSent(LoginCodeMail::class, function ($m) use (&$code) {
            $code = $m->code;

            return true;
        });

        return $code;
    }

    public function test_otp_login_creates_personal_workspace(): void
    {
        $code = $this->requestCode('Baru@Example.com');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertDatabaseMissing('login_otps', ['code_hash' => $code]);

        $this->postJson($this->host('app', '/api/auth/otp/verify'), ['email' => 'baru@example.com', 'code' => $code])
            ->assertOk()->assertJsonPath('data.user.email', 'baru@example.com');
        $this->assertAuthenticated();

        $user = User::where('email', 'baru@example.com')->firstOrFail();
        $tenant = $user->personalTenant;
        $this->assertSame('personal', $tenant->type->value);
        $this->assertSame('personal_free', $tenant->plan->key);
        $this->assertTrue(TenantMember::withoutGlobalScope(TenantScope::class)->where(['tenant_id' => $tenant->id, 'user_id' => $user->id])->exists());
        $this->assertSame(6, LeadStage::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->count());
        $this->assertSame(1, Profile::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->count());
    }

    public function test_wrong_code_counts_attempts_and_burns_after_five(): void
    {
        $code = $this->requestCode('rina@example.com');
        $wrong = $code === '000000' ? '111111' : '000000';
        for ($i = 1; $i <= 4; $i++) {
            $this->postJson($this->host('app', '/api/auth/otp/verify'), ['email' => 'rina@example.com', 'code' => $wrong])
                ->assertStatus(422)->assertJsonPath('error.message', 'Kode belum cocok. Sisa '.(5 - $i).' percobaan.');
        }
        $this->postJson($this->host('app', '/api/auth/otp/verify'), ['email' => 'rina@example.com', 'code' => $wrong])
            ->assertStatus(422)->assertJsonPath('error.message', 'Kode salah 5 kali. Minta kode baru.');
        $this->postJson($this->host('app', '/api/auth/otp/verify'), ['email' => 'rina@example.com', 'code' => $code])
            ->assertStatus(422)->assertJsonPath('error.message', 'Kode sudah tidak berlaku. Minta kode baru.');
        $this->assertGuest();
    }

    public function test_otp_request_is_throttled_per_email(): void
    {
        Mail::fake();
        for ($i = 0; $i < 3; $i++) {
            $this->postJson($this->host('app', '/api/auth/otp/request'), ['email' => 'x@example.com'])->assertOk();
        }
        $this->postJson($this->host('app', '/api/auth/otp/request'), ['email' => 'x@example.com'])->assertStatus(429);
    }

    public function test_existing_user_logs_in_to_same_account(): void
    {
        $user = $this->makeUser();
        $code = $this->requestCode('rina@example.com');
        $this->postJson($this->host('app', '/api/auth/otp/verify'), ['email' => 'rina@example.com', 'code' => $code])->assertOk();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::where('email', 'rina@example.com')->count(), 'tidak membuat akun ganda');
    }

    public function test_blocked_user_cannot_log_in(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['blocked_at' => now()])->save();
        $code = $this->requestCode('rina@example.com');
        $this->postJson($this->host('app', '/api/auth/otp/verify'), ['email' => 'rina@example.com', 'code' => $code])->assertStatus(422);
        $this->assertGuest();
    }

    private function adminLogin(string $email, string $password)
    {
        return $this->postJson($this->host('panel', '/api/auth/login'), compact('email', 'password'));
    }

    public function test_admin_has_no_otp_login(): void
    {
        $this->postJson($this->host('panel', '/api/auth/otp/request'), ['email' => 'owner@tapcard.test'])->assertNotFound();
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

    public function test_dev_login_code_only_works_in_local_environment(): void
    {
        config(['tapcard.dev_login_code' => '123456']);
        $this->makeUser();

        // APP_ENV=testing → kode acak seperti produksi
        $this->assertNotSame('123456', $this->requestCode('rina@example.com'));

        // APP_ENV=local → kode tetap (action dipanggil langsung; env local di test menyalakan cek CSRF)
        $this->app['env'] = 'local';
        Mail::fake();
        app(RequestOtp::class)->handle('rina@example.com', '127.0.0.1');
        Mail::assertSent(LoginCodeMail::class, fn ($m) => $m->code === '123456');
        $this->app['env'] = 'testing';
        $this->postJson($this->host('app', '/api/auth/otp/verify'), ['email' => 'rina@example.com', 'code' => '123456'])->assertOk();
        $this->assertAuthenticated();
    }
}
