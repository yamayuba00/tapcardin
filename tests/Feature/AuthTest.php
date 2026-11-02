<?php

namespace Tests\Feature;

use App\Models\PlatformStaff;
use App\Models\User;
use App\Support\Altcha;
use Database\Seeders\DummyAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Pengguna app. masuk dengan Google atau kartu NFC + PIN (lihat CardLoginTest); panel staf dengan email + password. */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_email_password_register_and_reset_endpoints_are_gone_on_app(): void
    {
        $this->makeUser()->forceFill(['password' => 'rahasia123'])->save();
        foreach (['/api/auth/login', '/api/auth/register', '/api/auth/register/verify', '/api/auth/password/forgot', '/api/auth/password/reset', '/api/auth/otp/request'] as $path) {
            $this->postJson($this->host('app', $path), ['email' => 'rina@example.com', 'password' => 'rahasia123'])->assertNotFound();
        }
        $this->assertGuest();
    }

    public function test_dev_login_only_works_in_local_environment_for_dummy_accounts(): void
    {
        config(['tapcard.dev_login_code' => '123456']);
        $dev = fn (string $email) => $this->postJson($this->host('app', '/api/auth/dev'), ['email' => $email]);
        $this->makeUser('rina@example.com');

        $dev('rina@example.com')->assertNotFound(); // APP_ENV=testing → mati seperti produksi
        $this->assertGuest();

        $this->app['env'] = 'local';
        $this->withSession(['_token' => 'tes-csrf'])->withHeader('X-CSRF-TOKEN', 'tes-csrf'); // env local menyalakan cek CSRF
        $dev('rina@example.com')->assertStatus(422); // bukan akun dummy
        $this->assertGuest();
        $dummy = array_key_first(DummyAccountSeeder::ACCOUNTS);
        User::where('email', $dummy)->exists() || $this->makeUser($dummy);
        config(['tapcard.dev_login_code' => null]);
        $dev($dummy)->assertNotFound(); // tanpa DEV_LOGIN_CODE tetap mati
        config(['tapcard.dev_login_code' => '123456']);
        $dev($dummy)->assertOk()->assertJsonPath('data.user.email', $dummy);
        $this->assertAuthenticated();
        $this->app['env'] = 'testing';
    }

    public function test_altcha_endpoint_serves_fresh_v3_challenge(): void
    {
        $this->getJson($this->host('app', '/api/auth/altcha'))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonStructure(['parameters' => ['algorithm', 'cost', 'expiresAt', 'keyLength', 'keyPrefix', 'nonce', 'salt'], 'signature'])->assertJsonPath('parameters.algorithm', 'SHA-256');
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
