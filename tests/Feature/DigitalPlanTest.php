<?php

namespace Tests\Feature;

use App\Actions\Auth\DeleteAccount;
use App\Actions\Auth\LoginUser;
use App\Enums\CardStatus;
use App\Models\Card;
use App\Models\Connection;
use App\Models\Profile;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Paket kartu digital (kuota tap + 1 bio link, terpisah dari paket kartu fisik) & skema hapus akun. */
class DigitalPlanTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private int $ip = 1;

    private function digitalOf(User $user): Card
    {
        return Card::acrossTenants()->where(['holder_user_id' => $user->id, 'form' => 'digital'])->firstOrFail();
    }

    /** Kunjungan dari pengunjung berbeda (IP lain) → lolos dedupe, dihitung sebagai tap. */
    private function visit(Card $card)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '10.1.0.'.$this->ip++])->getJson($this->host('tap', "/api/c/{$card->card_token}"));
    }

    private function api(string $method, string $path, array $data = [])
    {
        return $this->json($method, $this->host('app', "/api$path"), $data);
    }

    public function test_digital_card_quota_blocks_profile_when_used_up_and_notifies_owner_once(): void
    {
        $rina = $this->makeUser();
        $card = $this->digitalOf($rina);
        $this->assertSame(1500, $card->tap_quota);
        $card->update(['tap_quota' => 10, 'tap_used' => 8]);

        $this->visit($card)->assertJsonPath('data.state', 'profile'); // 9/10 = 90%
        $this->assertSame(1, DB::table('notifications')->where(['user_id' => $rina->id, 'type' => 'tap_quota'])->count());
        $this->visit($card)->assertJsonPath('data.state', 'profile'); // 10/10 → habis, tap ini masih dilayani
        $this->visit($card)->assertJsonPath('data.state', 'inactive')->assertJsonPath('data.reason', 'quota');
        $this->visit($card)->assertJsonPath('data.reason', 'quota');
        $this->assertSame(10, $card->fresh()->tap_used, 'tap yang ditolak tidak memakai kuota');
        $this->assertSame([90, 100], DB::table('notifications')->where(['user_id' => $rina->id, 'type' => 'tap_quota'])
            ->orderBy('id')->pluck('data')->map(fn ($d) => json_decode($d, true)['level'])->all());

        // kunjungan ulang (tidak dihitung) dari pengunjung yang sama juga tertahan
        $this->withServerVariables(['REMOTE_ADDR' => '10.1.0.1'])->getJson($this->host('tap', "/api/c/{$card->card_token}"))->assertJsonPath('data.reason', 'quota');
    }

    public function test_physical_card_has_no_tap_quota(): void
    {
        $card = $this->makeCard(CardStatus::Active, $this->makeUser());
        $this->assertNull($card->fresh()->tap_quota);
        $this->visit($card)->assertJsonPath('data.state', 'profile');
        $this->visit($card)->assertJsonPath('data.state', 'profile');
        $this->assertSame(0, $card->fresh()->tap_used, 'kuota hanya untuk kartu digital');
    }

    public function test_topup_adds_taps_and_unlocks_profile(): void
    {
        $rina = $this->makeUser();
        $card = $this->digitalOf($rina);
        $card->update(['tap_quota' => 5, 'tap_used' => 5, 'tap_quota_alert' => 100]);
        $this->visit($card)->assertJsonPath('data.reason', 'quota');

        $this->actingAs($rina);
        $s = $this->api('GET', '/me/settings')->assertOk()->json('data.digital_card');
        $this->assertSame(['quota' => 5, 'used' => 5, 'remaining' => 0, 'exhausted' => true], $s['taps']);
        $this->assertSame(['taps' => 2000, 'price' => 70000], $s['topup']);
        $this->assertSame(1, $s['bio_pages']['limit']);

        $t = $this->api('POST', '/billing/tap-topup')->assertCreated()->json('data');
        $this->assertSame(70000, $t['invoice']['total']);
        $this->assertSame('topup', $t['invoice']['type']);
        $this->assertSame($t['invoice']['id'], $this->api('POST', '/billing/tap-topup')->json('data.invoice.id'), 'invoice terbuka dipakai ulang');
        $this->assertSame($t['invoice']['id'], $this->api('GET', '/me/settings')->json('data.digital_card.open_invoice'));
        $this->api('GET', "/billing/invoices/{$t['invoice']['id']}")->assertJsonPath('data.return_to', '/settings');

        $this->api('POST', "/billing/invoices/{$t['invoice']['id']}/simulate", ['result' => 'paid', 'method' => 'QRIS'])->assertJsonPath('data.outcome', 'paid');
        $card->refresh();
        $this->assertSame([2005, 0], [$card->tap_quota, $card->tap_quota_alert]);
        $this->assertSame('personal_free', $rina->personalTenant->fresh()->plan->key, 'paket workspace tidak berubah');
        $this->visit($card)->assertJsonPath('data.state', 'profile');
        $this->assertDatabaseHas('audit_logs', ['action' => 'billing.topup_paid']);
    }

    public function test_digital_card_gets_one_bio_link_physical_gets_three(): void
    {
        $rina = $this->makeUser();
        $this->actingAs($rina);
        $digital = Profile::acrossTenants()->find($this->digitalOf($rina)->profile_id);
        $this->api('GET', "/profiles/{$digital->public_id}/pages")->assertJsonPath('meta.limit', 1);
        $this->api('POST', "/profiles/{$digital->public_id}/pages", ['name' => 'Pameran'])->assertJsonPath('error.code', 'bio_page_limit');

        $physical = Profile::acrossTenants()->create(['tenant_id' => $rina->personal_tenant_id, 'user_id' => $rina->id, 'label' => 'Fisik', 'display_name' => 'Rina']);
        $this->makeCard(CardStatus::Active, $rina)->update(['profile_id' => $physical->id]); // 1 kartu = 1 profil
        $this->api('GET', "/profiles/{$physical->public_id}/pages")->assertJsonPath('meta.limit', 3);
        $this->api('POST', "/profiles/{$physical->public_id}/pages", ['name' => 'Pameran'])->assertCreated();
        $this->api('POST', "/profiles/{$physical->public_id}/pages", ['name' => 'Personal'])->assertCreated();
        $this->api('POST', "/profiles/{$physical->public_id}/pages", ['name' => 'Lagi'])->assertJsonPath('error.code', 'bio_page_limit');
    }

    public function test_deleted_account_releases_identity_and_signing_up_again_starts_fresh(): void
    {
        $rina = $this->makeUser();
        $rina->update(['handle' => 'rina']);
        $old = $this->digitalOf($rina);
        $this->actingAs($rina)->deleteJson($this->host('app', '/api/me'), ['confirm_email' => 'rina@example.com'])->assertOk();

        $gone = User::withTrashed()->find($rina->id);
        $this->assertSoftDeleted($gone);
        $this->assertSame(["deleted-{$rina->id}@invalid", null], [$gone->email, $gone->handle]);
        $this->assertSame(CardStatus::Revoked, $old->fresh()->status);
        $this->assertSame('pending_deletion', Tenant::find($rina->personal_tenant_id)->status->value);
        $this->getJson($this->host('tap', "/api/c/{$old->card_token}"))->assertJsonPath('data.reason', 'revoked');

        // daftar lagi dengan email yang sama → akun baru yang kosong, bukan yang lama
        $again = app(LoginUser::class)->byEmail('rina@example.com', 'Rina Baru');
        $this->assertNotSame($rina->id, $again->id);
        $this->assertNotSame($rina->personal_tenant_id, $again->personal_tenant_id);
        $this->assertSame(0, Connection::acrossTenants()->where('tenant_id', $again->personal_tenant_id)->count());
        $this->assertNotSame($old->id, $this->digitalOf($again)->id);
        $this->assertSame(1500, $this->digitalOf($again)->tap_quota, 'kuota tap mulai dari awal');
    }

    public function test_purge_after_retention_deletes_personal_data_but_keeps_history(): void
    {
        $rina = $this->makeUser();
        $card = $this->digitalOf($rina);
        $this->visit($card);
        Connection::acrossTenants()->create(['tenant_id' => $rina->personal_tenant_id, 'name' => 'Budi', 'email' => 'budi@example.com', 'source' => 'manual',
            'stage_id' => DB::table('lead_stages')->where('tenant_id', $rina->personal_tenant_id)->value('id')]);
        $tapCount = DB::table('taps')->where('card_id', $card->id)->count();
        $this->assertGreaterThan(0, $tapCount);

        app(DeleteAccount::class)->handle($rina);
        Artisan::call('tapcard:prune');
        $this->assertSame(1, Profile::acrossTenants()->where('user_id', $rina->id)->count(), 'belum 30 hari: data masih ada');

        DB::table('users')->where('id', $rina->id)->update(['deleted_at' => now()->subDays(31)]);
        Artisan::call('tapcard:prune');

        $this->assertSame(0, Profile::acrossTenants()->where('user_id', $rina->id)->count());
        $this->assertSame(0, Connection::acrossTenants()->where('tenant_id', $rina->personal_tenant_id)->count());
        $this->assertSame('Pengguna dihapus', DB::table('users')->where('id', $rina->id)->value('name'));
        $this->assertSame('deleted', DB::table('tenants')->where('id', $rina->personal_tenant_id)->value('status'));
        // riwayat: kartu (dicabut) & tap tetap ada, tanpa identitas
        $this->assertSame('revoked', DB::table('cards')->where('id', $card->id)->value('status'));
        $this->assertSame($tapCount, DB::table('taps')->where('card_id', $card->id)->whereNull('holder_user_id')->count());
    }

    public function test_owner_of_company_workspace_must_hand_it_over_before_deleting(): void
    {
        $rina = $this->makeUser();
        Tenant::create(['type' => 'company', 'name' => 'PT Maju', 'slug' => 'maju', 'owner_user_id' => $rina->id, 'status' => 'active', 'plan_id' => DB::table('plans')->where('key', 'team')->value('id'), 'settings' => Tenant::DEFAULT_SETTINGS]);
        $this->actingAs($rina)->deleteJson($this->host('app', '/api/me'), ['confirm_email' => 'rina@example.com'])->assertJsonPath('error.code', 'owns_workspace');
        $this->assertNotSoftDeleted($rina);
    }
}
