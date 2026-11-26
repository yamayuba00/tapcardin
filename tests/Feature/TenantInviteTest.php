<?php

namespace Tests\Feature;

use App\Enums\TenantType;
use App\Mail\TenantInvite as TenantInviteMail;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantInvite;
use App\Models\TenantMember;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Undangan karyawan lewat email, aktivasi di host perusahaan, dan login email + kata sandi (host perusahaan saja). */
class TenantInviteTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Tenant $org;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->makeUser('owner@majujaya.id', 'Owner MJ');
        $this->org = Tenant::create([
            'type' => TenantType::Company, 'name' => 'Maju Jaya', 'slug' => 'majujaya', 'owner_user_id' => $this->owner->id,
            'status' => 'active', 'plan_id' => Plan::where('key', 'business')->value('id'), 'settings' => Tenant::DEFAULT_SETTINGS,
        ]);
        $this->join($this->owner, 'owner');
    }

    private function join(User $user, string $role): void
    {
        app(CurrentTenant::class)->run($this->org, fn () => TenantMember::create([
            'user_id' => $user->id, 'role_id' => Role::system($role)->id, 'status' => 'active',
        ]));
    }

    private function invite(string $email = 'budi@majujaya.id', string $role = 'member')
    {
        return $this->actingAs($this->owner)->postJson($this->host('majujaya', '/api/company/invites'), ['email' => $email, 'role' => $role]);
    }

    /** Token dari tautan di email undangan terakhir (satu-satunya tempat token plaintext muncul). */
    private function tokenFromMail(): string
    {
        $sent = Mail::sent(TenantInviteMail::class);
        $url = $sent->last()->url;

        return substr($url, strrpos($url, '/') + 1);
    }

    private function accept(string $token, string $email = 'budi@majujaya.id', string $password = 'rahasia123')
    {
        return $this->postJson($this->host('majujaya', "/api/invites/$token/accept"), [
            'email' => $email, 'name' => 'Budi', 'password' => $password, 'password_confirmation' => $password,
        ]);
    }

    public function test_invite_stores_only_a_hashed_token_and_sends_the_email(): void
    {
        Mail::fake();
        $this->invite()->assertCreated()->assertJsonPath('data.status', 'pending');

        $token = $this->tokenFromMail();
        $this->assertSame(64, strlen($token));
        $row = TenantInvite::acrossTenants()->firstOrFail();
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertSame('budi@majujaya.id', $row->email);
        $this->assertStringNotContainsString($token, json_encode($row->getAttributes()));
        $this->assertEqualsWithDelta(now()->addHours(72)->timestamp, $row->expires_at->timestamp, 60);
    }

    public function test_inviting_again_replaces_the_token_and_the_old_link_stops_working(): void
    {
        Mail::fake();
        $this->invite();
        $first = $this->tokenFromMail();
        $this->invite();
        $this->assertSame(1, TenantInvite::acrossTenants()->count());

        $second = $this->tokenFromMail();
        $this->assertNotSame($first, $second);
        $this->accept($first)->assertStatus(422)->assertJsonPath('error.code', 'invite_invalid');
    }

    public function test_activation_with_the_invited_email_creates_an_active_member(): void
    {
        Mail::fake();
        $this->invite();

        $this->accept($this->tokenFromMail(), 'BUDI@majujaya.id')->assertOk()->assertJsonPath('data.redirect', '/dashboard');
        $user = User::where('email', 'budi@majujaya.id')->firstOrFail();
        $this->assertTrue(Hash::check('rahasia123', $user->password));
        $this->assertAuthenticatedAs($user);
        $member = TenantMember::acrossTenants()->where(['tenant_id' => $this->org->id, 'user_id' => $user->id])->firstOrFail();
        $this->assertSame('active', $member->status->value);
        $this->assertSame('member', $member->role->key);
        $this->assertNotNull(TenantInvite::acrossTenants()->firstOrFail()->accepted_at);
    }

    public function test_wrong_email_is_rejected_and_the_invite_stays_open(): void
    {
        Mail::fake();
        $this->invite();
        $token = $this->tokenFromMail();

        $this->accept($token, 'orang.lain@majujaya.id')->assertStatus(422)
            ->assertJsonPath('error.fields.email.0', 'Email tidak sama dengan email undangan.');
        $this->assertFalse(User::where('email', 'orang.lain@majujaya.id')->exists());
        $this->assertNull(TenantInvite::acrossTenants()->firstOrFail()->accepted_at);
        $this->accept($token)->assertOk();
    }

    public function test_used_revoked_and_expired_tokens_get_the_same_generic_answer(): void
    {
        Mail::fake();
        $this->invite();
        $token = $this->tokenFromMail();
        $this->accept($token)->assertOk();
        $this->accept($token)->assertStatus(422)->assertJsonPath('error.code', 'invite_invalid');
        $this->getJson($this->host('majujaya', "/api/invites/$token"))->assertNotFound();

        $this->invite('rina@majujaya.id');
        $revoked = $this->tokenFromMail();
        TenantInvite::acrossTenants()->where('email', 'rina@majujaya.id')->update(['revoked_at' => now()]);
        $this->accept($revoked, 'rina@majujaya.id')->assertStatus(422)->assertJsonPath('error.code', 'invite_invalid');

        $this->invite('sari@majujaya.id');
        $expired = $this->tokenFromMail();
        $this->travel(73)->hours();
        $this->accept($expired, 'sari@majujaya.id')->assertStatus(422)
            ->assertJsonPath('error.message', 'Undangan ini tidak berlaku lagi. Minta owner mengirim undangan baru.');
        $this->assertFalse(User::where('email', 'sari@majujaya.id')->exists());
    }

    public function test_show_returns_workspace_and_role_but_not_the_email(): void
    {
        Mail::fake();
        $this->invite();
        $token = $this->tokenFromMail();

        $this->getJson($this->host('majujaya', "/api/invites/$token"))->assertOk()
            ->assertJsonPath('data.workspace.name', 'Maju Jaya')
            ->assertJsonMissingPath('data.email');
    }

    public function test_resend_and_revoke_only_work_on_open_invites(): void
    {
        Mail::fake();
        $this->invite();
        $invite = TenantInvite::acrossTenants()->firstOrFail();
        $this->actingAs($this->owner)->getJson($this->host('majujaya', '/api/company/invites'))
            ->assertOk()->assertJsonPath('data.0.status', 'pending')->assertJsonPath('data.0.email', 'budi@majujaya.id');

        $this->actingAs($this->owner)->postJson($this->host('majujaya', "/api/company/invites/{$invite->id}/resend"))->assertOk();
        Mail::assertSent(TenantInviteMail::class, 2);

        $this->actingAs($this->owner)->deleteJson($this->host('majujaya', "/api/company/invites/{$invite->id}"))->assertOk();
        $this->actingAs($this->owner)->getJson($this->host('majujaya', '/api/company/invites'))->assertJsonCount(0, 'data');
        $this->actingAs($this->owner)->postJson($this->host('majujaya', "/api/company/invites/{$invite->id}/resend"))->assertStatus(422);
    }

    public function test_members_cannot_invite_and_staff_emails_are_refused(): void
    {
        Mail::fake();
        $member = $this->makeUser('anggota@majujaya.id', 'Anggota');
        $this->join($member, 'member');
        $this->actingAs($member)->postJson($this->host('majujaya', '/api/company/invites'), ['email' => 'x@majujaya.id', 'role' => 'member'])->assertForbidden();
        Mail::assertNothingSent();

        $this->makeStaff('platform_owner', 'staf@tapcard.test');
        $this->invite('staf@tapcard.test')->assertStatus(422);
        $this->assertSame(0, TenantInvite::acrossTenants()->count());
    }

    public function test_password_login_works_only_for_members_on_the_company_host(): void
    {
        $budi = $this->makeUser('budi@majujaya.id', 'Budi');
        $budi->forceFill(['password' => 'rahasia123'])->save();
        $this->join($budi, 'member');
        $url = $this->host('majujaya', '/api/auth/password');

        $this->postJson($url, ['email' => 'budi@majujaya.id', 'password' => 'salah-banget'])
            ->assertStatus(422)->assertJsonPath('error.fields.email.0', 'Email atau password salah.');
        $this->assertGuest();

        $this->postJson($url, ['email' => 'budi@majujaya.id', 'password' => 'rahasia123'])
            ->assertOk()->assertJsonPath('data.user.email', 'budi@majujaya.id');
        $this->assertAuthenticatedAs($budi);

        $this->postJson($this->host('app', '/api/auth/password'), ['email' => 'budi@majujaya.id', 'password' => 'rahasia123'])->assertNotFound();
    }

    public function test_password_login_refuses_non_members_and_accounts_without_a_password(): void
    {
        $outsider = $this->makeUser('luar@example.com', 'Orang Luar');
        $outsider->forceFill(['password' => 'rahasia123'])->save();
        $this->postJson($this->host('majujaya', '/api/auth/password'), ['email' => 'luar@example.com', 'password' => 'rahasia123'])
            ->assertStatus(422)->assertJsonPath('error.fields.email.0', 'Email atau password salah.');

        $google = $this->makeUser('google@majujaya.id', 'Google');
        $this->join($google, 'member');
        $this->postJson($this->host('majujaya', '/api/auth/password'), ['email' => 'google@majujaya.id', 'password' => 'apa-saja-1'])
            ->assertStatus(422)->assertJsonPath('error.fields.email.0', 'Email atau password salah.');
        $this->assertGuest();
    }

    public function test_activation_for_an_existing_account_links_it_without_duplicating_the_user(): void
    {
        Mail::fake();
        $existing = $this->makeUser('budi@majujaya.id', 'Budi Lama');
        $this->invite();
        $this->accept($this->tokenFromMail())->assertOk();

        $this->assertSame(1, User::where('email', 'budi@majujaya.id')->count());
        $this->assertTrue(TenantMember::acrossTenants()->where(['tenant_id' => $this->org->id, 'user_id' => $existing->id, 'status' => 'active'])->exists());
    }

    public function test_activation_never_overwrites_an_existing_password(): void
    {
        Mail::fake();
        $existing = $this->makeUser('budi@majujaya.id', 'Budi Lama');
        $existing->forceFill(['password' => 'kata-lama-123'])->save();
        $this->invite();

        $this->accept($this->tokenFromMail(), 'budi@majujaya.id', 'kata-baru-456')
            ->assertStatus(422)->assertJsonPath('error.code', 'password_mismatch');
        $this->assertTrue(Hash::check('kata-lama-123', $existing->fresh()->password));
        $this->assertNull(TenantInvite::acrossTenants()->firstOrFail()->accepted_at);

        $this->accept($this->tokenFromMail(), 'budi@majujaya.id', 'kata-lama-123')->assertOk();
        $this->assertTrue(Hash::check('kata-lama-123', $existing->fresh()->password));
    }
}
