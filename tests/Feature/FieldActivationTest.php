<?php

namespace Tests\Feature;

use App\Enums\CardStatus;
use App\Models\Card;
use App\Models\CardLoginKey;
use App\Models\CardSale;
use App\Models\PlatformStaff;
use App\Models\Profile;
use App\Models\ProfileField;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Aktivasi di tempat (penjualan langsung): staf mengaktifkan kartu untuk pelanggan, pelanggan masuk dengan kartu + kode akun. */
class FieldActivationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function staff(string $role = 'production'): User
    {
        $u = $this->makeUser("$role@tapcard.test", 'Staf');
        PlatformStaff::create(['user_id' => $u->id, 'role_id' => Role::system($role)->id, 'status' => 'active', 'last_reverified_at' => now()]);

        return $u;
    }

    private function panel(string $method, string $path, array $data = [])
    {
        return $this->json($method, $this->host('panel', "/api$path"), $data);
    }

    private function activate(Card $card, array $extra = [])
    {
        return $this->panel('POST', '/field/activate', [
            'token' => $card->card_token,
            'name' => 'Sari Wulandari', 'email' => 'Sari@Example.com', 'whatsapp' => '0812 7777 8888',
            'title' => 'Pemilik', 'company' => 'Warung Sari', 'amount' => 75000, 'paid' => false,
            ...$extra,
        ]);
    }

    public function test_staff_activates_card_for_new_customer_who_then_logs_in_with_card_and_code(): void
    {
        $staff = $this->staff();
        $card = $this->makeCard(); // stok: belum diaktifkan

        $this->actingAs($staff)->panel('GET', '/field/card?q='.$card->formattedShortCode())->assertOk()
            ->assertJsonPath('data.token', $card->card_token)->assertJsonPath('data.ready', true);

        $res = $this->activate($card)->assertCreated()
            ->assertJsonPath('data.customer.email', 'sari@example.com')->assertJsonPath('data.customer.new_account', true)
            ->assertJsonPath('data.sale.status', 'unpaid')->assertJsonPath('data.sale.amount', 75000);
        $code = $res->json('data.account_code');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        $customer = User::where('email', 'sari@example.com')->first();
        $card->refresh();
        $this->assertSame(CardStatus::Active, $card->status);
        $this->assertSame($customer->id, $card->holder_user_id);
        $profile = Profile::withoutGlobalScope(TenantScope::class)->find($card->profile_id);
        $this->assertSame('Sari Wulandari', $profile->display_name);
        $this->assertSame('Warung Sari', $profile->company);
        $this->assertTrue(ProfileField::withoutGlobalScope(TenantScope::class)->where(['profile_id' => $profile->id, 'type' => 'whatsapp'])->exists(),
            'WhatsApp dari form masuk ke bio kartu');
        $this->assertDatabaseHas('audit_logs', ['action' => 'card.field_activated', 'actor_user_id' => $staff->id]);
        $this->assertNotSame($code, CardLoginKey::first()->pin_hash, 'hanya hash yang disimpan');

        // pelanggan menempelkan kartu (URL chip membawa kunci) lalu mengetik kode akun
        auth()->guard('web')->logout();
        $this->postJson($this->host('app', '/api/auth/card'), [
            'token' => $card->card_token, 'key' => $card->chipKey(), 'code' => $code, 'altcha' => $this->altcha(),
        ])->assertOk();
        $this->assertAuthenticatedAs($customer);
    }

    public function test_staff_needs_no_activation_code_but_cannot_give_away_a_card_reserved_for_an_order(): void
    {
        $this->actingAs($this->staff());
        $card = $this->makeCard();
        $this->activate($card)->assertCreated(); // tanpa kode aktivasi
        $this->assertSame(0, $card->fresh()->activation_attempts);

        $buyer = $this->makeUser('pemesan@example.com', 'Pemesan');
        $reserved = $this->makeCard(CardStatus::Unclaimed, null, $buyer);
        $this->panel('GET', '/field/card?q='.$reserved->card_token)->assertJsonPath('data.reserved_for', 'pemesan@example.com');
        $this->activate($reserved, ['email' => 'orang.lain@example.com'])->assertStatus(422)->assertJsonPath('error.code', 'card_reserved');
        $this->assertSame(1, CardSale::count());
        $this->activate($reserved, ['email' => 'pemesan@example.com'])->assertCreated()->assertJsonPath('data.customer.new_account', false);
        $this->assertSame($buyer->id, $reserved->fresh()->holder_user_id);
    }

    public function test_qc_passed_batch_cards_offer_direct_activation(): void
    {
        $this->actingAs($this->staff());
        $batchCard = $this->makeCard(CardStatus::Manufactured);
        $other = $this->makeCard(CardStatus::Manufactured);
        $other->update(['qc_passed_at' => null]); // belum lolos QC
        $rows = collect($this->panel('GET', "/batches/{$batchCard->batch->public_id}/cards")->assertOk()->json('data'));
        $this->assertTrue($rows->firstWhere('token', $batchCard->card_token)['activatable']);
        $this->assertFalse(collect($this->panel('GET', "/batches/{$other->batch->public_id}/cards")->json('data'))->firstWhere('token', $other->card_token)['activatable']);

        // aktivasi langsung dari stok lolos QC (belum "Kirim")
        $this->activate($batchCard)->assertCreated();
        $this->assertSame(CardStatus::Active, $batchCard->fresh()->status);
    }

    public function test_existing_customer_email_gets_the_card_in_their_account(): void
    {
        $this->actingAs($this->staff());
        $existing = $this->makeUser('sari@example.com', 'Sari Lama');
        $card = $this->makeCard();

        $this->activate($card, ['paid' => true, 'payment_method' => 'qris'])->assertCreated()
            ->assertJsonPath('data.customer.new_account', false)->assertJsonPath('data.sale.status', 'paid')
            ->assertJsonPath('data.sale.payment_method', 'qris');
        $this->assertSame($existing->id, $card->fresh()->holder_user_id);
        $this->assertSame(1, User::where('email', 'sari@example.com')->count());
    }

    public function test_active_or_unchecked_cards_cannot_be_sold_and_payment_needs_a_method(): void
    {
        $this->actingAs($this->staff());
        $active = $this->makeCard(CardStatus::Active, holder: $this->makeUser('pemilik@example.com'));
        $this->activate($active)->assertStatus(422)->assertJsonPath('error.code', 'card_not_activatable');

        $raw = $this->makeCard(CardStatus::Manufactured);
        $raw->forceFill(['qc_passed_at' => null])->save();
        $this->activate($raw)->assertStatus(422);

        $qc = $this->makeCard(CardStatus::Manufactured); // lolos QC, belum dikirim → boleh dijual langsung
        $this->activate($qc, ['paid' => true])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['payment_method']]]);
        $this->activate($qc, ['paid' => true, 'payment_method' => 'manual'])->assertCreated();
        $this->assertSame(CardStatus::Active, $qc->fresh()->status);
    }

    public function test_staff_marks_payment_later_on_card_detail(): void
    {
        $this->actingAs($this->staff());
        $card = $this->makeCard();
        $this->activate($card)->assertCreated();
        $url = "/cards/{$card->card_token}/sale";

        $this->panel('GET', "/cards/{$card->card_token}")->assertOk()->assertJsonPath('data.sale.status', 'unpaid');
        $this->panel('PUT', $url, ['status' => 'paid'])->assertStatus(422);
        $this->panel('PUT', $url, ['status' => 'paid', 'payment_method' => 'manual', 'amount' => 80000])->assertOk()
            ->assertJsonPath('data.status', 'paid')->assertJsonPath('data.payment_label', 'Manual / tunai')->assertJsonPath('data.amount', 80000);
        $this->assertNotNull(CardSale::first()->paid_at);
        $this->panel('PUT', $url, ['status' => 'unpaid'])->assertOk()->assertJsonPath('data.paid_at', null);
        $this->assertDatabaseHas('audit_logs', ['action' => 'card.sale_updated']);
    }

    public function test_only_staff_with_card_management_can_activate(): void
    {
        $card = $this->makeCard();
        $this->actingAs($this->staff('finance'));
        $this->activate($card)->assertForbidden();
        $this->panel('GET', '/field/card?q='.$card->card_token)->assertForbidden();
    }
}
