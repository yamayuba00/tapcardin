<?php

namespace Tests\Feature;

use App\Enums\CardStatus;
use App\Models\Batch;
use App\Models\Card;
use App\Models\PlatformStaff;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** PRD §10.9 / AFD F-04 — API panel pemilik, peran platform, alur produksi ujung ke ujung. */
class PlatformTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function staff(string $role, string $email = 'staf@tapcard.test'): User
    {
        $user = $this->makeUser($email, 'Staf');
        PlatformStaff::create(['user_id' => $user->id, 'role_id' => Role::system($role)->id, 'status' => 'active', 'last_reverified_at' => now()]);

        return $user;
    }

    private function api(string $method, string $path, array $data = [])
    {
        return $this->json($method, $this->host('panel', "/api$path"), $data);
    }

    public function test_spa_shell_and_guest_401(): void
    {
        $this->get($this->host('panel', '/batches'))->assertOk()->assertSee('id="app"', false);
        $this->api('GET', '/dashboard')->assertUnauthorized();
    }

    public function test_non_staff_is_forbidden(): void
    {
        $this->actingAs($this->makeUser());
        $this->api('GET', '/dashboard')->assertForbidden()->assertJsonPath('error.code', 'not_staff');
    }

    public function test_staff_must_reverify_every_12_hours(): void
    {
        $staff = $this->staff('platform_owner');
        PlatformStaff::where('user_id', $staff->id)->update(['last_reverified_at' => now()->subHours(13)]);
        $this->actingAs($staff);
        $this->api('GET', '/dashboard')->assertUnauthorized()->assertJsonPath('error.code', 'reverify_required');
    }

    public function test_me_lists_platform_permissions(): void
    {
        $this->actingAs($this->staff('production'));
        $perms = $this->api('GET', '/me')->assertOk()->assertJsonPath('data.role', 'Produksi')->json('data.permissions');
        $this->assertContains('production.manage', $perms);
        $this->assertNotContains('staff.manage', $perms);
    }

    public function test_role_matrix_on_platform_endpoints(): void
    {
        $this->actingAs($this->staff('production'));
        $this->api('GET', '/batches')->assertOk();
        $this->api('GET', '/staff')->assertForbidden();
        $this->api('GET', '/tenants')->assertForbidden();

        $this->actingAs($this->staff('support', 'support@tapcard.test'));
        $this->api('GET', '/users')->assertOk();
        $this->api('GET', '/batches')->assertForbidden();
    }

    public function test_full_production_flow_to_activation(): void
    {
        Storage::fake('local');
        $buyer = $this->makeUser('pembeli@example.com', 'Pembeli');
        $this->actingAs($this->staff('production'));

        $this->api('POST', '/batches', ['chip_type' => 'ntag213', 'quantity' => 2, 'holder_email' => 'tidakada@example.com'])
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $id = $this->api('POST', '/batches', ['chip_type' => 'ntag213', 'quantity' => 2, 'holder_email' => 'pembeli@example.com'])
            ->assertCreated()->json('data.id');
        $this->api('POST', "/batches/$id/generate")->assertOk()->assertJsonPath('data.counts.tokens', 2)->assertJsonPath('data.has_bundle', true);

        $batch = Batch::where('public_id', $id)->firstOrFail();
        $cards = Card::acrossTenants()->where('batch_id', $batch->id)->orderBy('sequence')->get();
        $csv = Storage::disk('local')->get($batch->print_bundle_path);
        $this->assertStringContainsString('http://tap.localhost/c/'.$cards[0]->card_token.'?src=qr', $csv);
        $this->get($this->host('panel', "/api/batches/$id/bundle.csv"))->assertOk();
        $this->api('GET', "/batches/$id/print")->assertOk()->assertJsonCount(2, 'data.cards');

        $this->assertSame($cards[0]->card_token, $this->api('GET', "/batches/$id/next")->json('data.token'));
        foreach ($cards as $i => $c) {
            $this->api('POST', "/cards/{$c->card_token}/encoded", ['uid' => '04:A1:B2:C3:D4:E5:F'.$i])->assertOk();
        }
        // encode ulang kartu #2 dengan chip milik kartu #1 → ditolak
        $this->api('POST', "/cards/{$cards[1]->card_token}/encoded", ['uid' => '04A1B2C3D4E5F0'])->assertStatus(422)->assertJsonPath('error.code', 'uid_duplicate');

        $bad = $this->api('POST', "/cards/{$cards[0]->card_token}/qc", ['nfc_url' => $cards[0]->resolverUrl(), 'qr_url' => $cards[1]->qrUrl()])->json('data');
        $this->assertFalse($bad['passed']);
        $this->assertSame('NFC dan QR menunjuk kartu berbeda.', $bad['reason']);
        foreach ($cards as $c) {
            $this->api('POST', "/cards/{$c->card_token}/qc", ['nfc_url' => $c->resolverUrl(), 'qr_url' => $c->qrUrl()])->assertJsonPath('data.passed', true);
        }

        $this->api('POST', "/batches/$id/ship", ['tracking_number' => 'JNE123'])->assertOk()
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.counts.shipped', 2);
        $this->assertSame([CardStatus::Unclaimed, CardStatus::Unclaimed], Card::acrossTenants()->where('batch_id', $batch->id)->pluck('status')->all());

        $this->actingAs($buyer)->postJson($this->host('app', '/api/cards/activate'), ['token' => $cards[0]->card_token])->assertOk();
        $this->getJson($this->host('tap', "/api/c/{$cards[0]->card_token}"))->assertJsonPath('data.profile.display_name', 'Pembeli');

        $this->assertDatabaseHas('audit_logs', ['action' => 'batch.created', 'scope' => 'platform']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'card.encoded']);
    }

    public function test_duplicate_chip_uid_is_rejected(): void
    {
        $this->actingAs($this->staff('production'));
        $a = $this->makeCard(CardStatus::Manufactured);
        $b = $this->makeCard(CardStatus::Manufactured);
        $this->api('POST', "/cards/{$a->card_token}/encoded", ['uid' => '04A1B2C3D4E5F6'])->assertOk();
        $this->api('POST', "/cards/{$b->card_token}/encoded", ['uid' => '04a1b2c3d4e5f6'])->assertStatus(422)->assertJsonPath('error.code', 'uid_duplicate');
    }

    public function test_suspend_workspace_requires_reason_and_neutralizes_cards(): void
    {
        $this->actingAs($this->staff('platform_admin'));
        $rina = $this->makeUser();
        $card = $this->makeCard(CardStatus::Active, $rina);
        $tid = $rina->personalTenant->public_id;

        $this->api('POST', "/tenants/$tid/suspend")->assertStatus(422)->assertJsonPath('error.fields.reason.0', 'Alasan wajib diisi.');
        $this->api('POST', "/tenants/$tid/suspend", ['reason' => 'Pelanggaran'])->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->getJson($this->host('tap', "/api/c/{$card->card_token}"))->assertJsonPath('data.reason', 'suspended');
        $this->assertDatabaseHas('audit_logs', ['action' => 'tenant.suspended']);
        $this->api('POST', "/tenants/$tid/reactivate")->assertOk()->assertJsonPath('data.status', 'active');
    }

    public function test_last_platform_owner_cannot_be_revoked(): void
    {
        $owner = PlatformStaff::where('role_id', Role::system('platform_owner')->id)->firstOrFail();
        $owner->update(['last_reverified_at' => now()]);
        $this->actingAs($owner->user);
        $this->api('PATCH', "/staff/{$owner->id}", ['status' => 'revoked'])->assertStatus(422)->assertJsonPath('error.code', 'last_owner');
        $this->assertSame('active', $owner->fresh()->status);
    }

    public function test_status_change_requires_allowed_transition_and_reason(): void
    {
        $this->actingAs($this->staff('support'));
        $card = $this->makeCard(CardStatus::Unclaimed);
        $this->api('POST', "/cards/{$card->card_token}/status", ['status' => 'active', 'reason' => 'x'])->assertStatus(422);
        $this->api('POST', "/cards/{$card->card_token}/status", ['status' => 'revoked', 'reason' => 'Dibatalkan'])->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->assertSame(CardStatus::Revoked, Card::acrossTenants()->find($card->id)->status);
    }

    public function test_reissue_activation_code_returns_code_once(): void
    {
        $this->actingAs($this->staff('support'));
        $card = $this->makeCard(CardStatus::Unclaimed);
        $code = $this->api('POST', "/cards/{$card->card_token}/reissue-code")->assertOk()->json('data.code');
        $this->assertMatchesRegularExpression('/^[0-9A-Z]{4}-[0-9A-Z]{4}$/', $code);
        $this->assertDatabaseHas('audit_logs', ['action' => 'card.activation_code_reissued']);
    }

    public function test_invite_staff_issues_temporary_password_once(): void
    {
        $owner = PlatformStaff::where('role_id', Role::system('platform_owner')->id)->firstOrFail();
        $owner->update(['last_reverified_at' => now()]);
        $this->actingAs($owner->user);

        $temp = $this->api('POST', '/staff', ['email' => 'baru@tapcard.test', 'role_id' => Role::system('support')->id])
            ->assertOk()->json('data.issued.temporary_password');
        $this->assertSame(12, strlen($temp));
        $this->assertNull($this->api('GET', '/staff')->json('data.issued'), 'hanya ditampilkan sekali');

        $this->app['auth']->forgetGuards();
        $this->postJson($this->host('panel', '/api/auth/login'), ['email' => 'baru@tapcard.test', 'password' => $temp])->assertOk();
    }

    public function test_lost_card_replacement_flow(): void
    {
        $rina = $this->makeUser();
        $old = $this->makeCard(CardStatus::Lost, $rina);
        $other = $this->makeCard(CardStatus::Active, $this->makeUser('lain@example.com', 'Lain'));

        // pemilik: hanya kartu hilang yang bisa diminta gantinya
        $this->actingAs($rina)->postJson($this->host('app', "/api/cards/{$other->card_token}/replacement"))->assertNotFound();
        $this->actingAs($rina)->postJson($this->host('app', "/api/cards/{$old->card_token}/replacement"), ['note' => 'Kirim ke kantor'])
            ->assertOk()->assertJsonPath('data.replacement_requested_at', fn ($v) => $v !== null);

        $this->actingAs($this->staff('production'));
        $this->api('GET', '/dashboard')->assertJsonPath('data.replacements_pending', 1);
        $this->assertSame([$old->card_token], array_column($this->api('GET', '/cards?replacement=1')->json('data'), 'token'));
        $this->api('GET', "/cards/{$old->card_token}")->assertJsonPath('data.replacement.note', 'Kirim ke kantor');

        $id = $this->api('POST', "/cards/{$old->card_token}/replacement-batch")->assertCreated()->json('data.batch.id');
        $this->api('POST', "/cards/{$old->card_token}/replacement-batch")->assertJsonPath('error.code', 'replacement_exists');
        $this->api('POST', "/cards/{$other->card_token}/replacement-batch")->assertJsonPath('error.code', 'not_replaceable');
        $this->assertSame([], $this->api('GET', '/cards?replacement=1')->json('data'), 'sudah ditangani');

        $this->api('POST', "/batches/$id/generate")->assertOk()->assertJsonPath('data.counts.tokens', 1);
        $batch = Batch::where('public_id', $id)->firstOrFail();
        $new = Card::acrossTenants()->where('batch_id', $batch->id)->firstOrFail();
        $this->assertSame($old->id, $new->replaces_card_id);
        $new->update(['status' => CardStatus::Unclaimed]); // lewati encode/QC/kirim (diuji di alur produksi)

        // pemilik lama = pembeli → aktif tanpa kode, profil sama, kartu lama dicabut
        $this->actingAs($rina)->postJson($this->host('app', '/api/cards/activate'), ['token' => $new->card_token])->assertOk();
        $this->assertSame($old->profile_id, $new->fresh()->profile_id);
        $this->assertSame(CardStatus::Revoked, $old->fresh()->status);
        $this->getJson($this->host('tap', "/api/c/{$old->card_token}"))->assertJsonPath('data.state', 'inactive');
    }
}
