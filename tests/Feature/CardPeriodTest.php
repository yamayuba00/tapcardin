<?php

namespace Tests\Feature;

use App\Enums\CardStatus;
use App\Models\Card;
use App\Models\CardSale;
use App\Models\StaffAccount;
use App\Models\User;
use App\Support\CardPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Masa pakai kartu penjualan langsung: 6 bulan dari aktivasi, tenggang 7 hari, perpanjangan, pengingat, kuitansi. */
class CardPeriodTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function staff(): StaffAccount
    {
        return $this->makeStaff('production', 'production@tapcard.test', 'Staf');
    }

    private function panel(string $method, string $path, array $data = [])
    {
        return $this->json($method, $this->host('panel', "/api$path"), $data);
    }

    private function field(Card $card): array
    {
        return $this->panel('POST', '/field/activate', [
            'token' => $card->card_token, 'name' => 'Sari Wulandari', 'email' => 'sari@example.com',
            'amount' => 75000, 'paid' => true, 'payment_method' => 'qris',
        ])->assertCreated()->json('data');
    }

    private function ownedCard(User $owner, CardStatus $status): Card
    {
        $card = $this->makeCard($status, $owner);
        $card->update(['tenant_id' => $owner->personal_tenant_id]);

        return $card->fresh();
    }

    private function resolve(string $token)
    {
        return $this->getJson($this->host('tap', "/api/c/{$token}"));
    }

    public function test_field_activation_sets_six_month_period_from_activation(): void
    {
        $this->actingAs($this->staff());
        $card = $this->makeCard(CardStatus::Manufactured);
        $data = $this->field($card);

        $card->refresh();
        $this->assertNotNull($card->valid_until);
        $this->assertEqualsWithDelta(now()->addMonths(6)->timestamp, $card->valid_until->timestamp, 120);
        $this->assertNotNull($data['card']['valid_until']);
    }

    public function test_online_cards_have_no_period_and_keep_working(): void
    {
        $owner = $this->makeUser();
        $card = $this->makeCard(CardStatus::Active, $owner);
        $this->assertNull($card->fresh()->valid_until);
        $this->resolve($card->card_token)->assertOk()->assertJsonPath('data.state', 'profile');
    }

    public function test_card_works_within_grace_then_goes_inactive(): void
    {
        $card = $this->makeCard(CardStatus::Active, $this->makeUser());
        $card->update(['valid_until' => now()->subDay()]);
        $this->resolve($card->card_token)->assertJsonPath('data.state', 'profile');

        $card->update(['valid_until' => now()->subDays(8)]);
        Cache::forget("card:{$card->card_token}");
        $this->resolve($card->card_token)->assertJsonPath('data.state', 'inactive')->assertJsonPath('data.reason', 'expired');
        $this->get($this->host('tap', "/c/{$card->card_token}/qr.svg"))->assertNotFound();
    }

    public function test_renewal_invoice_price_and_owner_only(): void
    {
        $owner = $this->makeUser();
        $card = $this->ownedCard($owner, CardStatus::Active);
        $card->update(['valid_until' => now()->addDays(10)]);

        $other = $this->makeUser('lain@example.com', 'Lain');
        $this->actingAs($other)->postJson($this->host('app', "/api/cards/{$card->card_token}/renew"))->assertNotFound();

        $this->actingAs($owner);
        $res = $this->postJson($this->host('app', "/api/cards/{$card->card_token}/renew"))->assertCreated();
        $this->assertSame(CardPeriod::renewalPrice(), $res->json('data.invoice.total'));
        $this->assertSame('card_renewal', $res->json('data.invoice.type'));
    }

    public function test_paid_renewal_extends_from_period_end_when_in_grace_and_from_now_when_expired(): void
    {
        $owner = $this->makeUser();
        $card = $this->ownedCard($owner, CardStatus::Active);
        $end = now()->addDays(10)->startOfSecond();
        $card->update(['valid_until' => $end]);

        $this->actingAs($owner);
        $invoiceId = $this->postJson($this->host('app', "/api/cards/{$card->card_token}/renew"))->json('data.invoice.id');
        $this->postJson($this->host('app', "/api/billing/invoices/{$invoiceId}/simulate"), ['result' => 'paid', 'method' => 'QRIS'])->assertOk();
        $this->assertEqualsWithDelta($end->addMonths(6)->timestamp, $card->fresh()->valid_until->timestamp, 2);

        $card->update(['valid_until' => now()->subDays(8)->startOfSecond()]);
        $invoiceId = $this->postJson($this->host('app', "/api/cards/{$card->card_token}/renew"))->json('data.invoice.id');
        $this->postJson($this->host('app', "/api/billing/invoices/{$invoiceId}/simulate"), ['result' => 'paid', 'method' => 'QRIS'])->assertOk();
        $this->assertEqualsWithDelta(now()->addMonths(6)->timestamp, $card->fresh()->valid_until->timestamp, 120);
    }

    public function test_reminders_are_sent_once_per_period(): void
    {
        $owner = $this->makeUser();
        $card = $this->makeCard(CardStatus::Active, $owner);
        $card->update(['form' => Card::PHYSICAL, 'valid_until' => now()->addDays(30)->addHours(2)]);

        $this->artisan('tapcard:card-periods')->assertSuccessful();
        $this->artisan('tapcard:card-periods')->assertSuccessful();
        $this->assertSame(1, DB::table('notifications')->where('user_id', $owner->id)->where('type', 'card_period')->count());
    }

    public function test_receipts_are_visible_only_to_the_buyer_and_staff(): void
    {
        $staff = $this->staff();
        $this->actingAs($staff);
        $card = $this->makeCard(CardStatus::Manufactured);
        $this->field($card);
        $buyer = User::whereRaw('lower(email) = ?', ['sari@example.com'])->firstOrFail();
        $sale = CardSale::where('card_id', $card->id)->firstOrFail();

        $this->actingAs($buyer)->getJson($this->host('app', "/api/receipts/{$sale->public_id}"))->assertOk()
            ->assertJsonPath('data.kind', 'card_sale')->assertJsonPath('data.amount', 75000);
        $this->actingAs($this->makeUser('lain@example.com', 'Lain'))->getJson($this->host('app', "/api/receipts/{$sale->public_id}"))->assertNotFound();
        $this->assertSame(1, count($this->actingAs($buyer)->getJson($this->host('app', '/api/receipts'))->json('data')));
        $this->actingAs($staff);
        $this->panel('GET', "/receipts/{$sale->public_id}")->assertOk()->assertJsonPath('data.seller', 'Staf');
    }
}
