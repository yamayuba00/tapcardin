<?php

namespace Tests\Feature;

use App\Enums\CardStatus;
use App\Models\Batch;
use App\Models\Card;
use App\Models\PlatformStaff;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Jual kartu door-to-door: batch desain seragam, kartu polos ditulis di tempat (encode = lolos QC), ringkasan harian. */
class FieldSellTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function staff(string $email): User
    {
        $u = $this->makeUser($email, 'Staf');
        PlatformStaff::create(['user_id' => $u->id, 'role_id' => Role::system('production')->id, 'status' => 'active', 'last_reverified_at' => now()]);

        return $u;
    }

    private function panel(string $method, string $path, array $data = [])
    {
        return $this->json($method, $this->host('panel', "/api$path"), $data);
    }

    private function batch(bool $uniform, int $qty = 3): Batch
    {
        $id = $this->panel('POST', '/batches', ['chip_type' => 'ntag215', 'quantity' => $qty, 'uniform' => $uniform])
            ->assertCreated()->assertJsonPath('data.uniform', $uniform)->json('data.id');
        $this->panel('POST', "/batches/$id/generate")->assertOk();

        return Batch::where('public_id', $id)->firstOrFail();
    }

    public function test_blank_card_is_written_on_the_spot_auto_qc_then_sold(): void
    {
        $andi = $this->staff('andi@tapcard.test');
        $this->actingAs($andi);
        $this->batch(false, 2); // batch biasa: tidak ditawarkan sebagai kartu polos
        $b = $this->batch(true, 3);
        $this->panel('GET', '/field/summary')->assertJsonPath('data.stock.blank', 3)->assertJsonPath('data.stock.ready', 0);

        $first = $this->panel('POST', '/field/blank')->assertOk()->json('data');
        $card = Card::acrossTenants()->where('card_token', $first['token'])->firstOrFail();
        $this->assertSame([$b->id, 1], [$card->batch_id, $card->sequence]);
        $this->assertSame($card->chipUrl(), $first['url'], 'URL chip membawa kunci kartu');
        $this->assertSame($first['token'], $this->panel('POST', '/field/blank')->json('data.token'), 'petugas yang sama: cadangan yang sama');

        // petugas lain tidak mendapat kartu yang sedang dicadangkan, dan tidak boleh melapor encode-nya
        $budi = $this->staff('budi@tapcard.test');
        $this->actingAs($budi);
        $other = $this->panel('POST', '/field/blank')->json('data.token');
        $this->assertNotSame($first['token'], $other);
        $this->panel('POST', "/field/cards/{$first['token']}/encoded", ['uid' => '04A1B2C3D4E5F6'])->assertForbidden();

        $this->actingAs($andi);
        $this->panel('POST', "/field/cards/{$first['token']}/encoded", ['uid' => '04A1B2C3D4E5F6'])->assertOk()->assertJsonPath('data.ready', true);
        $card->refresh();
        $this->assertNotNull($card->encoded_at);
        $this->assertNotNull($card->qc_passed_at, 'desain seragam: encode = lolos QC');
        $this->panel('GET', "/field/card?q={$card->card_token}")->assertJsonPath('data.ready', true);

        $this->panel('POST', '/field/activate', ['token' => $card->card_token, 'name' => 'Sari', 'email' => 'sari@example.com', 'whatsapp' => '081277778888', 'amount' => 75000, 'paid' => true, 'payment_method' => 'manual'])
            ->assertCreated();
        $this->assertSame(CardStatus::Active, $card->fresh()->status);
        $this->panel('GET', '/field/summary')->assertJsonPath('data.today.count', 1)->assertJsonPath('data.today.total', 75000)
            ->assertJsonPath('data.today.unpaid', 0)->assertJsonPath('data.stock.blank', 2);
    }

    public function test_batches_are_split_into_direct_custom_and_company_channels_automatically(): void
    {
        $this->actingAs($this->staff('andi@tapcard.test'));
        $direct = $this->batch(true, 1);
        $stock = $this->batch(false, 1); // tanpa workspace & bukan desain seragam → Custom
        $buyer = $this->makeUser('pembeli@example.com', 'Pembeli');
        $custom = $this->panel('POST', '/batches', ['chip_type' => 'ntag215', 'quantity' => 1, 'holder_email' => 'pembeli@example.com'])->json('data.id');
        $pt = Tenant::create(['type' => 'company', 'name' => 'PT Maju', 'slug' => 'maju', 'owner_user_id' => $buyer->id, 'status' => 'active',
            'plan_id' => DB::table('plans')->where('key', 'team')->value('id'), 'settings' => Tenant::DEFAULT_SETTINGS]);
        $company = Batch::create(['code' => 'BPT-1', 'status' => 'draft', 'chip_type' => 'ntag215', 'quantity' => 1, 'tenant_id' => $pt->id, 'created_by' => $buyer->id]);

        $codes = fn (string $ch) => collect($this->panel('GET', "/batches?channel=$ch")->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$direct->public_id], $codes('direct'));
        $this->assertSame(collect([$stock->public_id, $custom])->sort()->values()->all(), $codes('custom'));
        $this->assertSame([$company->public_id], $codes('company'));
        $this->panel('GET', "/batches/{$company->public_id}")->assertJsonPath('data.channel', 'company')->assertJsonPath('data.channel_label', 'Perusahaan')
            ->assertJsonPath('data.tenant', 'PT Maju');
        $this->panel('GET', "/batches/{$direct->public_id}")->assertJsonPath('data.channel_label', 'Jual langsung');
    }

    public function test_direct_stock_batch_carries_a_front_and_back_design_that_can_be_changed(): void
    {
        $andi = $this->staff('andi@tapcard.test');
        // desain dibuat di Design Studio (app.) akun petugas
        $this->actingAs($andi);
        $mine = $this->postJson($this->host('app', '/api/designs'), ['template' => 'onyx', 'name' => 'Door to door v1'])->assertCreated()->json('data.id');
        $mine2 = $this->postJson($this->host('app', '/api/designs'), ['template' => 'onyx', 'name' => 'Door to door v2'])->assertCreated()->json('data.id');
        $budi = $this->staff('budi@tapcard.test');
        $this->actingAs($budi);
        $other = $this->postJson($this->host('app', '/api/designs'), ['template' => 'onyx', 'name' => 'Milik Budi'])->assertCreated()->json('data.id');

        $this->actingAs($andi);
        $list = $this->panel('GET', '/batches/designs')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['Door to door v1', 'Door to door v2'], array_column($list['designs'], 'name'), 'hanya desain milik sendiri');
        $this->assertNotEmpty($list['designs'][0]['front']);
        // template Onyx memuat nama, QR, & kode kartu — diperingatkan karena stok jual langsung dicetak sama semua
        $this->assertSame(['nama / jabatan otomatis', 'QR unik', 'kode kartu'], $list['designs'][0]['per_card']);
        $this->assertStringEndsWith("/designs/{$list['designs'][0]['id']}", $list['designs'][0]['studio_url']);

        $this->panel('POST', '/batches', ['chip_type' => 'ntag215', 'quantity' => 2, 'uniform' => true, 'design' => $other])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['design']]]);
        $id = $this->panel('POST', '/batches', ['chip_type' => 'ntag215', 'quantity' => 2, 'uniform' => true, 'design' => $mine])->assertCreated()->json('data.id');
        $this->panel('GET', "/batches/$id")->assertJsonPath('data.design.id', $mine)->assertJsonPath('data.design.name', 'Door to door v1');

        $this->panel('PATCH', "/batches/$id/design", ['design' => $mine2])->assertOk()->assertJsonPath('data.design.name', 'Door to door v2');
        $this->panel('PATCH', "/batches/$id/design", ['design' => null])->assertOk()->assertJsonPath('data.design', null);
        $this->assertDatabaseHas('audit_logs', ['action' => 'batch.design_changed']);
    }

    public function test_no_blank_stock_gives_a_clear_message(): void
    {
        $this->actingAs($this->staff('andi@tapcard.test'));
        $this->batch(false, 1);
        $this->panel('POST', '/field/blank')->assertStatus(422)->assertJsonPath('error.code', 'no_blank_stock');
    }

    public function test_encode_station_on_uniform_batch_skips_qc(): void
    {
        $this->actingAs($this->staff('andi@tapcard.test'));
        $uniform = $this->batch(true, 1);
        $normal = $this->batch(false, 1);
        foreach ([[$uniform, true, '04AAAAAAAAAAAA'], [$normal, false, '04BBBBBBBBBBBB']] as [$b, $qc, $uid]) {
            $token = $b->cards()->first()->card_token;
            $this->panel('POST', "/cards/$token/encoded", ['uid' => $uid])->assertOk();
            $this->assertSame($qc, Card::acrossTenants()->where('card_token', $token)->value('qc_passed_at') !== null);
        }
    }
}
