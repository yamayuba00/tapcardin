<?php

namespace Tests\Feature;

use App\Actions\Cards\CreateDigitalCard;
use App\Authz\PlanFeatures;
use App\Enums\CardStatus;
use App\Models\Card;
use App\Models\Profile;
use App\Tenancy\TenantScope;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Kartu digital — bio link, QR layar & tautan tanpa kartu fisik. */
class DigitalCardTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function digitalOf($user): ?Card
    {
        return Card::acrossTenants()->where(['holder_user_id' => $user->id, 'form' => 'digital'])->first();
    }

    private function api(string $method, string $path, array $data = [])
    {
        return $this->json($method, $this->host('app', "/api$path"), $data);
    }

    public function test_new_user_gets_active_digital_card_with_own_bio_that_resolves_publicly(): void
    {
        $rina = $this->makeUser();
        $card = $this->digitalOf($rina);
        $this->assertNotNull($card);
        $this->assertSame(CardStatus::Active, $card->status);
        $this->assertNull($card->chip_type);
        $this->assertNull($card->batch_id);
        $this->assertSame($rina->personal_tenant_id, $card->tenant_id);
        $this->assertSame('Kerja', Profile::withoutGlobalScope(TenantScope::class)->find($card->profile_id)->label, 'memakai profil awal saat daftar');

        $this->getJson($this->host('tap', "/api/c/{$card->card_token}?src=link"))->assertOk()
            ->assertJsonPath('data.state', 'profile')->assertJsonPath('data.profile.display_name', 'Rina Saputra');
    }

    public function test_one_digital_card_per_user_per_workspace_is_enforced_by_index_and_action(): void
    {
        $rina = $this->makeUser();
        $again = app(CreateDigitalCard::class)->handle($rina, $rina->personalTenant);
        $this->assertSame($this->digitalOf($rina)->id, $again->id);

        $this->expectException(QueryException::class);
        Card::acrossTenants()->create([
            'form' => 'digital', 'card_token' => 'ZZZZZZZZZZ', 'short_code' => 'ZZZZZZZZ', 'status' => 'active',
            'tenant_id' => $rina->personal_tenant_id, 'holder_user_id' => $rina->id, 'profile_id' => $this->digitalOf($rina)->profile_id,
        ]);
    }

    public function test_digital_card_cannot_have_chip_or_be_marked_lost(): void
    {
        $rina = $this->makeUser();
        $card = $this->digitalOf($rina);
        $this->actingAs($rina);

        $d = $this->api('GET', "/cards/{$card->card_token}")->assertOk()->assertJsonPath('data.form', 'digital')->json('data');
        $this->assertSame(['paused'], $d['allowed']);
        $this->assertStringEndsWith("/c/{$card->card_token}?src=link", $d['share']['link']);
        $this->assertNull($d['share']['handle_url']);

        $this->api('PATCH', "/cards/{$card->card_token}", ['status' => 'lost'])->assertJsonPath('error.code', 'invalid_transition');
        $this->api('PATCH', "/cards/{$card->card_token}", ['status' => 'paused'])->assertOk()->assertJsonPath('data.status', 'paused');

        $this->expectException(QueryException::class);
        Card::acrossTenants()->whereKey($card->id)->update(['chip_type' => 'ntag213']);
    }

    public function test_digital_card_does_not_count_toward_plan_card_limit(): void
    {
        $rina = $this->makeUser();
        $this->makeCard(CardStatus::Active, $rina);
        $this->makeCard(CardStatus::Active, $rina);
        $this->actingAs($rina);
        $usage = collect($this->api('GET', '/billing')->json('data.usage'))->firstWhere('label', 'Kartu aktif');
        $this->assertSame(2, $usage['used'], 'paket gratis: 2 kartu fisik + kartu digital');

        $cards = $this->api('GET', '/cards')->assertOk();
        $this->assertSame('digital', $cards->json('data.0.form'), 'kartu digital di urutan pertama');
        $this->assertTrue($cards->json('meta.has_digital'));
    }

    public function test_handle_link_redirects_to_digital_card_and_account_deletion_revokes_it(): void
    {
        $rina = $this->makeUser();
        $rina->update(['handle' => 'rina']);
        $card = $this->digitalOf($rina);

        $this->get($this->host('tap', '/@rina'))->assertRedirect("/c/{$card->card_token}?src=link");
        $this->get($this->host('tap', '/@tidakada'))->assertNotFound();

        $this->actingAs($rina);
        $this->assertStringEndsWith('/@rina', $this->api('GET', "/cards/{$card->card_token}")->json('data.share.handle_url'));
        $this->assertSame($card->card_token, $this->api('GET', '/dashboard')->json('data.share_token'));

        $this->api('DELETE', '/me', ['confirm_email' => $rina->email])->assertOk();
        $this->assertSame(CardStatus::Revoked, $card->fresh()->status);
    }

    public function test_create_digital_endpoint_is_idempotent(): void
    {
        $rina = $this->makeUser();
        $this->actingAs($rina);
        $this->api('POST', '/cards/digital')->assertOk()->assertJsonPath('data.form', 'digital'); // sudah dibuat saat daftar → 200, tidak dobel
        $this->assertSame(1, Card::acrossTenants()->where(['holder_user_id' => $rina->id, 'form' => 'digital'])->count());
    }

    public function test_visual_uses_card_bio_and_real_qr_and_drops_chip_only_layers(): void
    {
        $rina = $this->makeUser();
        $card = $this->digitalOf($rina);
        $this->actingAs($rina);

        $v = $this->api('GET', "/cards/{$card->card_token}/visual")->assertOk()->json('data');
        $ids = fn ($side) => array_column($v[$side]['layers'], 'id');
        $this->assertNotContains('sys-nfc', $ids('front'));
        $this->assertNotContains('sys-tapzone', $ids('back'));
        $this->assertNotContains('sys-shortcode', $ids('back'));
        $this->assertContains('sys-qr', $ids('back'), 'QR menggantikan NFC');
        $this->assertSame('Rina Saputra', collect($v['front']['layers'])->firstWhere('id', 'name')['text']);
        $this->assertNull(collect($v['front']['layers'])->firstWhere('id', 'title'), 'jabatan kosong → teks disembunyikan, bukan contoh');
        $this->assertGreaterThan(20, $v['qr']['modules']);
        $this->assertNull($v['design']);

        $physical = $this->makeCard(CardStatus::Active, $rina);
        $p = $this->api('GET', "/cards/{$physical->card_token}/visual")->json('data');
        $this->assertContains('sys-nfc', array_column($p['front']['layers'], 'id'), 'kartu fisik tetap utuh');
    }

    public function test_digital_card_design_can_be_changed_to_template_or_own_design_only(): void
    {
        $rina = $this->makeUser();
        $card = $this->digitalOf($rina);
        $this->actingAs($rina);

        $v = $this->api('PUT', "/cards/{$card->card_token}/design", ['template' => 'ivory', 'orientation' => 'portrait'])->assertOk()->json('data');
        $this->assertSame('portrait', $v['orientation']);
        $this->assertSame('Kartu digital — Ivory', $v['design']['name']);
        $this->assertNotNull($card->fresh()->design_id);

        $lain = $this->makeUser('lain@example.com', 'Lain');
        $this->actingAs($lain);
        $theirs = $this->api('POST', '/designs', ['template' => 'onyx'])->assertCreated()->json('data.id');
        $this->actingAs($rina);
        $this->api('PUT', "/cards/{$card->card_token}/design", ['design' => $theirs])->assertNotFound();

        $physical = $this->makeCard(CardStatus::Active, $rina);
        $this->api('PUT', "/cards/{$physical->card_token}/design", ['template' => 'onyx'])->assertJsonPath('error.code', 'card_printed');
    }

    public function test_free_digital_card_qr_rotates_weekly_and_old_qr_stops_working(): void
    {
        $rina = $this->makeUser(); // Personal Free: qr_rotation_days = 7
        $card = $this->digitalOf($rina);

        $detail = $this->actingAs($rina)->api('GET', "/cards/{$card->card_token}")->assertOk()->assertJsonPath('data.qr_rotation.days', 7);
        $first = $detail->json('data.qr_url');
        $this->assertMatchesRegularExpression('~/s/[0-9A-Za-z]{12}$~', $first);
        $this->assertSame($first, $this->api('GET', "/cards/{$card->card_token}")->json('data.qr_url'), 'stabil dalam satu periode');

        $path = parse_url($first, PHP_URL_PATH);
        $this->get($this->host('tap', $path))->assertRedirect($card->qrUrl());

        $this->travel(8)->days();
        $second = $this->api('GET', "/cards/{$card->card_token}")->json('data.qr_url');
        $this->assertNotSame($first, $second, 'QR baru setelah 7 hari');
        $this->get($this->host('tap', $path))->assertStatus(410); // QR lama kedaluwarsa
        $this->get($this->host('tap', parse_url($second, PHP_URL_PATH)))->assertRedirect($card->qrUrl());
        $this->getJson($this->host('tap', "/api/c/{$card->card_token}"))->assertOk(); // tautan profil tetap

        // paket tanpa rotasi → QR permanen
        $rina->personalTenant->update(['limit_overrides' => ['qr_rotation_days' => null]]);
        PlanFeatures::forget($rina->personalTenant);
        $this->api('GET', "/cards/{$card->card_token}")->assertJsonPath('data.qr_url', $card->qrUrl())->assertJsonPath('data.qr_rotation', null);
    }
}
