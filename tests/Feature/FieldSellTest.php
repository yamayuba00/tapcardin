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
