<?php

namespace Tests\Feature;

use App\Enums\CardStatus;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Payment\PaymentService;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** OR-05 — paket & tagihan: checkout, gateway (simulator & payment link), aktivasi paket, siklus langganan. */
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
        $this->assertSame('simulator', $d['gateway']['code']);
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

    /** Gateway "link" (mis. QRIS) dengan konfigurasi PAYMENT_* — tanpa nama penyedia di kode. */
    private function useLinkGateway(array $webhook = []): void
    {
        config(['payment.driver' => 'link', 'payment.gateway' => [
            'name' => 'Gateway Uji', 'code' => 'qrisgw', 'api_url' => 'https://pay.test/api/v1', 'api_key' => 'key-uji', 'api_key_header' => 'X-Api-Key',
            'create_path' => '/payments', 'status_path' => '/payments/{id}', 'method' => 'QRIS', 'method_label' => 'QRIS', 'expires_hours' => 24, 'timeout' => 5,
            'webhook' => $webhook + ['verify' => 'signature', 'secret' => 'whsec_'.base64_encode('rahasia-webhook'), 'tolerance' => 300],
        ]]);
    }

    /** Webhook bertanda tangan gaya Svix: v1,base64(HMAC-SHA256(id.timestamp.body)). */
    private function signedWebhook(array $body, string $id, ?string $secret = 'rahasia-webhook')
    {
        $raw = json_encode($body);
        $ts = (string) now()->getTimestamp();
        $sig = 'v1,'.base64_encode(hash_hmac('sha256', "$id.$ts.$raw", (string) $secret, true));

        return $this->call('POST', 'http://localhost/webhooks/payment', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_SVIX_ID' => $id, 'HTTP_SVIX_TIMESTAMP' => $ts, 'HTTP_SVIX_SIGNATURE' => $sig,
        ], $raw);
    }

    public function test_link_gateway_checkout_and_signed_webhook_is_verified_amount_checked_and_idempotent(): void
    {
        $this->useLinkGateway();
        Http::fake(['pay.test/api/v1/payments' => Http::response(['payment_id' => 'pay_123', 'status' => 'pending', 'payment_link_url' => 'https://pay.test/link/pay_123'])]);
        $this->actingAs($this->makeUser());
        $this->assertSame(['code' => 'qrisgw', 'name' => 'Gateway Uji', 'simulator' => false, 'method' => 'QRIS'], $this->api('GET', '/billing')->json('data.gateway'));

        $c = $this->api('POST', '/billing/checkout', ['plan' => 'personal_pro', 'cycle' => 'monthly'])->assertCreated()->json('data');
        $this->assertSame('https://pay.test/link/pay_123', $c['payment_url']);
        Http::assertSent(fn ($r) => $r->hasHeader('X-Api-Key', 'key-uji') && $r['amount'] === 49000 && $r['order_id'] === $c['invoice']['id']
            && $r['payment_method_type_code'] === 'QRIS' && str_ends_with($r['success_return_url'], "/billing/invoices/{$c['invoice']['id']}?paid=1"));
        $inv = $this->invoices()->firstOrFail();
        $this->assertSame(['qrisgw', 'pay_123'], [$inv->payment_provider, $inv->payment_ref]);

        $body = fn ($amount = 49000) => ['type' => 'payment.completed', 'data' => ['payment_id' => 'pay_123', 'order_id' => $c['invoice']['id'], 'amount' => $amount, 'status' => 'completed', 'payment_method' => 'QRIS']];
        $this->signedWebhook($body(1000), 'evt_kurang')->assertOk()->assertJsonPath('outcome', 'rejected'); // nominal tidak cocok
        $this->assertSame('issued', $inv->fresh()->status);
        $this->signedWebhook($body(), 'evt_ok')->assertOk()->assertJsonPath('outcome', 'paid');
        $this->signedWebhook($body(), 'evt_ok')->assertJsonPath('outcome', 'duplicate');
        $this->assertSame(['paid', 'QRIS'], [$inv->fresh()->status, $inv->fresh()->payment_method]);
        $this->assertSame('personal_pro', $inv->tenant->fresh()->plan->key);
    }

    public function test_forged_webhook_never_pays_and_is_confirmed_via_gateway_api(): void
    {
        $this->useLinkGateway();
        Http::fake([
            'pay.test/api/v1/payments' => Http::response(['payment_id' => 'pay_9', 'payment_link_url' => 'https://pay.test/link/pay_9']),
            'pay.test/api/v1/payments/pay_9' => Http::sequence()
                ->push(['payment_id' => 'pay_9', 'status' => 'pending', 'amount' => 49000])
                ->push(['payment_id' => 'pay_9', 'status' => 'completed', 'amount' => 49000, 'payment_method' => 'QRIS']),
        ]);
        $this->actingAs($this->makeUser());
        $id = $this->api('POST', '/billing/checkout', ['plan' => 'personal_pro', 'cycle' => 'monthly'])->json('data.invoice.id');

        // tanda tangan palsu → isi webhook diabaikan; API gateway bilang masih pending → tetap belum lunas
        $forged = ['type' => 'payment.completed', 'data' => ['payment_id' => 'pay_9', 'order_id' => $id, 'amount' => 49000, 'status' => 'completed']];
        $this->signedWebhook($forged, 'evt_palsu', 'kunci-salah')->assertOk()->assertJsonPath('outcome', 'pending');
        $this->assertSame('issued', $this->invoices()->first()->status);
        $this->assertDatabaseHas('payment_events', ['external_id' => 'evt_palsu', 'signature_valid' => false]);

        // pengguna kembali dari halaman bayar → cek status ke gateway → lunas
        $this->api('POST', "/billing/invoices/$id/check")->assertOk()->assertJsonPath('data.status', 'paid');
    }

    public function test_reconcile_command_confirms_paid_invoices_when_webhook_is_missing(): void
    {
        $this->useLinkGateway(['verify' => 'none']);
        Http::fake([
            'pay.test/api/v1/payments' => Http::response(['id' => 'pay_7', 'payment_link_url' => 'https://pay.test/link/pay_7']),
            'pay.test/api/v1/payments/pay_7' => Http::response(['data' => ['id' => 'pay_7', 'status' => 'PAID', 'amount' => 490000]]),
        ]);
        $this->actingAs($this->makeUser());
        $this->api('POST', '/billing/checkout', ['plan' => 'personal_pro', 'cycle' => 'yearly'])->assertCreated();

        $this->artisan('tapcard:payments-reconcile')->expectsOutput('1 invoice dicek, 1 lunas.')->assertSuccessful();
        $this->assertSame('paid', $this->invoices()->first()->status);
        $this->artisan('tapcard:payments-reconcile')->expectsOutput('0 invoice dicek, 0 lunas.')->assertSuccessful();
    }

    public function test_unconfigured_gateway_gives_clear_error_and_simulator_is_refused_in_production(): void
    {
        config(['payment.driver' => 'link', 'payment.gateway.api_url' => null]);
        $this->actingAs($this->makeUser());
        $this->api('POST', '/billing/checkout', ['plan' => 'personal_pro', 'cycle' => 'monthly'])->assertStatus(503)->assertJsonPath('error.code', 'payment_unavailable');

        config(['payment.driver' => 'simulator']);
        $this->app['env'] = 'production';
        $this->assertNull(PaymentService::info());
        $this->app['env'] = 'testing';
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
