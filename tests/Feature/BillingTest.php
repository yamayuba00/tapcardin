<?php

namespace Tests\Feature;

use App\Enums\CardStatus;
use App\Models\Invoice;
use App\Models\PaymentEvent;
use App\Models\Plan;
use App\Models\Subscription;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** OR-05 — paket & tagihan: checkout, gateway (simulator & Midtrans), aktivasi paket, siklus langganan. */
class BillingTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function api(string $method, string $path, array $data = [])
    {
        return $this->json($method, $this->host('app', "/api$path"), $data);
    }

    private function invoices()
    {
        return Invoice::withoutGlobalScope(TenantScope::class);
    }

    public function test_billing_overview_lists_plans_usage_and_comparison(): void
    {
        $this->actingAs($this->makeUser());
        $d = $this->api('GET', '/billing')->assertOk()->json('data');
        $this->assertSame('personal_free', $d['plan']['key']);
        $this->assertSame(['personal_free', 'personal_pro'], array_column($d['plans'], 'key'));
        $pro = $d['plans'][1];
        $this->assertSame(49000 * 12 - 490000, $pro['yearly_saving']);
        $this->assertTrue(collect($pro['compare'])->firstWhere('label', 'QR aman (dinamis) sekali pakai')['value']);
        $this->assertSame('simulator', $d['gateway']);
        $this->assertSame(2, $d['usage'][0]['limit']);
    }

    public function test_checkout_and_simulated_payment_upgrade_to_pro_and_unlock_dynamic_qr(): void
    {
        $rina = $this->makeUser();
        $card = $this->makeCard(CardStatus::Active, $rina);
        $this->actingAs($rina);
        $this->api('POST', "/cards/{$card->card_token}/dynamic-qr")->assertStatus(402);

        $this->api('POST', '/billing/checkout', ['plan' => 'personal_free', 'cycle' => 'monthly'])->assertJsonPath('error.code', 'plan_free');
        $this->api('POST', '/billing/checkout', ['plan' => 'team', 'cycle' => 'monthly'])->assertJsonPath('error.code', 'plan_unavailable');
        $c = $this->api('POST', '/billing/checkout', ['plan' => 'personal_pro', 'cycle' => 'yearly', 'amount' => 1])->assertCreated()->json('data');
        $this->assertSame(490000, $c['invoice']['total'], 'harga dari tabel plans, bukan klien');
        $this->assertMatchesRegularExpression('#^INV/\d{4}/\d{2}/\d{6}$#', $c['invoice']['number']);
        $this->assertStringEndsWith("/billing/pay/{$c['invoice']['id']}", $c['payment_url']);
        $again = $this->api('POST', '/billing/checkout', ['plan' => 'personal_pro', 'cycle' => 'yearly'])->json('data.invoice.id');
        $this->assertSame($c['invoice']['id'], $again, 'invoice terbuka dipakai ulang');

        $this->api('POST', "/billing/invoices/{$c['invoice']['id']}/simulate", ['result' => 'failed', 'method' => 'QRIS'])->assertJsonPath('data.outcome', 'failed')->assertJsonPath('data.status', 'issued');
        $this->api('POST', "/billing/invoices/{$c['invoice']['id']}/simulate", ['result' => 'paid', 'method' => 'BCA Virtual Account'])
            ->assertOk()->assertJsonPath('data.outcome', 'paid')->assertJsonPath('data.status', 'paid')->assertJsonPath('data.payment_method', 'BCA Virtual Account');

        $rina->personalTenant->refresh()->unsetRelation('plan');
        $this->assertSame('personal_pro', $rina->personalTenant->plan->key);
        $sub = Subscription::withoutGlobalScope(TenantScope::class)->firstOrFail();
        $this->assertSame(['active', 'yearly'], [$sub->status, $sub->cycle]);
        $this->assertEqualsWithDelta(now()->addYear()->timestamp, $sub->current_period_end->timestamp, 5);
        $this->assertDatabaseHas('notifications', ['user_id' => $rina->id, 'type' => 'invoice_paid']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'billing.paid']);
        $this->api('POST', "/cards/{$card->card_token}/dynamic-qr")->assertOk();

        $this->api('POST', "/billing/invoices/{$c['invoice']['id']}/pay")->assertJsonPath('error.code', 'invoice_not_payable');
        $this->api('GET', '/billing/invoices')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.status', 'paid');
        $this->actingAs($this->makeUser('lain@example.com', 'Lain'))->api('GET', "/billing/invoices/{$c['invoice']['id']}")->assertNotFound();
    }

    public function test_midtrans_webhook_verifies_signature_amount_and_is_idempotent(): void
    {
        config(['services.payments.driver' => 'midtrans', 'services.midtrans.server_key' => 'SB-Mid-server-TEST']);
        Http::fake(['app.sandbox.midtrans.com/*' => Http::response(['token' => 'tok', 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/tok'])]);
        $rina = $this->makeUser();
        $this->actingAs($rina);
        $inv = $this->api('POST', '/billing/checkout', ['plan' => 'personal_pro', 'cycle' => 'monthly'])->assertCreated()->json('data');
        $this->assertStringStartsWith('https://app.sandbox.midtrans.com/', $inv['payment_url']);
        Http::assertSent(fn ($r) => $r['transaction_details']['gross_amount'] === 49000 && $r['transaction_details']['order_id'] === $inv['invoice']['number']);

        $notify = function (array $over = []) use ($inv) {
            $p = $over + ['order_id' => $inv['invoice']['number'], 'status_code' => '200', 'gross_amount' => '49000.00', 'transaction_status' => 'settlement',
                'transaction_id' => 'trx-1', 'payment_type' => 'bank_transfer', 'va_numbers' => [['bank' => 'bca']]];
            $p['signature_key'] ??= hash('sha512', $p['order_id'].$p['status_code'].$p['gross_amount'].'SB-Mid-server-TEST');

            return $this->postJson('http://localhost/webhooks/payments/midtrans', $p);
        };
        $notify(['signature_key' => 'palsu', 'transaction_id' => 'trx-x'])->assertOk()->assertJsonPath('outcome', 'rejected');
        $notify(['gross_amount' => '1000.00', 'transaction_id' => 'trx-y'])->assertJsonPath('outcome', 'rejected');
        $this->assertSame('issued', $this->invoices()->first()->status);

        $notify()->assertJsonPath('outcome', 'paid');
        $notify()->assertJsonPath('outcome', 'duplicate');
        $this->assertSame('bank transfer BCA', $this->invoices()->first()->payment_method);
        $this->assertSame(1, Subscription::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(3, PaymentEvent::count(), 'dua ditolak + satu lunas; notifikasi duplikat tidak disimpan lagi');
        $this->postJson('http://localhost/webhooks/payments/tidak-ada', [])->assertNotFound();
    }

    public function test_renewal_cancel_and_downgrade_lifecycle(): void
    {
        $rina = $this->makeUser();
        $this->actingAs($rina);
        $id = $this->api('POST', '/billing/checkout', ['plan' => 'personal_pro', 'cycle' => 'monthly'])->json('data.invoice.id');
        $this->api('POST', "/billing/invoices/$id/simulate", ['result' => 'paid', 'method' => 'QRIS'])->assertOk();

        // H-7 → invoice perpanjangan + notifikasi
        $this->travel(25)->days();
        $this->artisan('tapcard:subscriptions')->assertSuccessful();
        $renewal = $this->invoices()->where('status', 'issued')->firstOrFail();
        $this->assertNotNull($renewal->subscription_id);
        $this->assertDatabaseHas('notifications', ['user_id' => $rina->id, 'type' => 'invoice_due']);

        // membayar perpanjangan memperpanjang dari akhir periode, bukan dari hari ini
        $end = Subscription::withoutGlobalScope(TenantScope::class)->first()->current_period_end;
        $this->api('POST', "/billing/invoices/{$renewal->public_id}/pay")->assertOk();
        $renewal->update(['payment_provider' => 'simulator']);
        $this->api('POST', "/billing/invoices/{$renewal->public_id}/simulate", ['result' => 'paid', 'method' => 'QRIS'])->assertJsonPath('data.status', 'paid');
        $this->assertEquals($end->copy()->addMonthNoOverflow()->toDateString(), Subscription::withoutGlobalScope(TenantScope::class)->first()->current_period_end->toDateString());

        // batal di akhir periode → turun ke Free saat periode habis
        $this->api('POST', '/billing/cancel')->assertOk()->assertJsonPath('data.subscription.cancel_at_period_end', true);
        $this->travel(40)->days();
        $this->artisan('tapcard:subscriptions')->assertSuccessful();
        $this->assertSame('cancelled', Subscription::withoutGlobalScope(TenantScope::class)->first()->status);
        $this->assertSame(Plan::where('key', 'personal_free')->value('id'), $rina->personalTenant->fresh()->plan_id);
    }

    public function test_unpaid_renewal_goes_past_due_then_expires_after_grace(): void
    {
        $rina = $this->makeUser();
        $this->actingAs($rina);
        $id = $this->api('POST', '/billing/checkout', ['plan' => 'personal_pro', 'cycle' => 'monthly'])->json('data.invoice.id');
        $this->api('POST', "/billing/invoices/$id/simulate", ['result' => 'paid', 'method' => 'QRIS']);

        $this->travel(32)->days();
        $this->artisan('tapcard:subscriptions');
        $sub = Subscription::withoutGlobalScope(TenantScope::class)->first();
        $this->assertSame('past_due', $sub->status);
        $this->assertNotNull($sub->grace_until);
        $this->assertSame('personal_pro', Plan::find($rina->personalTenant->fresh()->plan_id)->key, 'fitur tetap selama masa tenggang');

        $this->travel(15)->days();
        $this->artisan('tapcard:subscriptions');
        $this->assertSame('expired', $sub->fresh()->status);
        $this->assertSame('personal_free', Plan::find($rina->personalTenant->fresh()->plan_id)->key);
    }

    public function test_old_admin_host_redirects_to_panel(): void
    {
        $this->get('http://admin.localhost/cards?status=lost')->assertRedirect('http://panel.localhost/cards?status=lost')->assertStatus(301);
        $this->get('http://panel.localhost/')->assertOk()->assertSee('id="app"', false);
    }
}
