<?php

namespace Tests\Feature;

use App\Actions\Billing\ApplyPayment;
use App\Console\Commands\Health;
use App\Models\Invoice;
use App\Models\PaymentEvent;
use App\Models\PaymentReview;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Payment\PaymentNotification;
use App\Services\Payment\PaymentService;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Temuan audit pembayaran: link basi, lunas tanpa nominal, bayar dobel, invoice batal dibayar, voucher, webhook palsu, simulator. */
class PaymentHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /** @var array<string, array{status:string, amount:?int}> status tiap pembayaran di gateway palsu */
    private array $gw = [];

    private int $created = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['payment.driver' => 'link', 'payment.gateway' => [
            'name' => 'Gateway Uji', 'code' => 'qrisgw', 'api_url' => 'https://pay.test/api/v1', 'api_key' => 'key-uji', 'api_key_header' => 'X-Api-Key',
            'create_path' => '/payments', 'status_path' => '/payments/{id}', 'method' => 'QRIS', 'method_label' => 'QRIS', 'expires_hours' => 1, 'timeout' => 5,
            'webhook' => ['verify' => 'signature', 'secret' => 'whsec_'.base64_encode('rahasia-webhook'), 'tolerance' => 300],
        ]]);
        Http::fake(function (HttpRequest $r) {
            if ($r->method() === 'POST') {
                $id = 'pay_'.(++$this->created);
                $this->gw[$id] = ['status' => 'pending', 'amount' => (int) $r['amount']];

                return Http::response(['payment_id' => $id, 'payment_link_url' => "https://pay.test/link/$id", 'order_id' => $r['order_id']]);
            }
            $id = basename(parse_url($r->url(), PHP_URL_PATH));

            return Http::response(['payment_id' => $id] + ($this->gw[$id] ?? ['status' => 'pending', 'amount' => null]));
        });
    }

    private function api(string $method, string $path, array $data = [])
    {
        return $this->json($method, $this->host('app', "/api$path"), $data);
    }

    private function invoice(string $id): Invoice
    {
        return Invoice::withoutGlobalScope(TenantScope::class)->where('public_id', $id)->firstOrFail();
    }

    private function webhook(array $data, string $eventId, string $secret = 'rahasia-webhook')
    {
        $raw = json_encode(['type' => 'payment.completed', 'data' => $data]);
        $ts = (string) now()->getTimestamp();

        return $this->call('POST', 'http://wbh.localhost/payment', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_SVIX_ID' => $eventId, 'HTTP_SVIX_TIMESTAMP' => $ts,
            'HTTP_SVIX_SIGNATURE' => 'v1,'.base64_encode(hash_hmac('sha256', "$eventId.$ts.$raw", $secret, true)),
        ], $raw);
    }

    /** Invoice perpanjangan: tanpa batas waktu bayar, jatuh tempo 7 hari lagi. */
    private function renewal(): string
    {
        static $n = 0;
        $n++;
        $this->actingAs($this->makeUser("pelanggan$n@example.com", "Pelanggan $n"));
        $id = $this->api('POST', '/billing/checkout', ['plan' => 'personal_pro', 'cycle' => 'monthly'])->assertCreated()->json('data.invoice.id');
        $this->invoice($id)->update(['expires_at' => null, 'due_at' => now()->addDays(7)]);

        return $id;
    }

    public function test_expired_link_is_never_handed_out_and_a_new_link_gets_a_new_order_id(): void
    {
        $id = $this->renewal();
        $first = $this->invoice($id);
        $this->assertSame('https://pay.test/link/pay_1', $first->payment_url);
        $this->assertNotNull($first->payment_url_expires_at);

        // masih hidup → link yang sama
        $this->assertSame('https://pay.test/link/pay_1', $this->api('POST', "/billing/invoices/$id/pay")->assertOk()->json('data.payment_url'));
        // lewat 1 jam → link lama mati: link baru dengan order_id berakhiran -r3 (percobaan ke-3), bukan link basi
        $this->travel(61)->minutes();
        $this->assertSame('https://pay.test/link/pay_2', $this->api('POST', "/billing/invoices/$id/pay")->assertOk()->json('data.payment_url'));
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r['order_id'] === "$id-r2");
        $this->assertSame('https://pay.test/link/pay_2', $this->api('GET', "/billing/invoices/$id")->json('data.payment_url'));

        // webhook untuk link pengganti (order_id -r2) tetap melunasi invoice yang sama
        $this->webhook(['payment_id' => 'pay_2', 'order_id' => "$id-r2", 'amount' => 49000, 'status' => 'completed'], 'evt_r2')->assertJsonPath('outcome', 'paid');
        $this->assertSame(['paid', 'pay_2'], [$this->invoice($id)->status, $this->invoice($id)->payment_ref]);
    }

    public function test_failed_link_is_replaced_and_old_link_already_paid_is_applied_not_replaced(): void
    {
        $id = $this->renewal();
        $this->gw['pay_1']['status'] = 'failed';
        $this->assertSame('https://pay.test/link/pay_2', $this->api('POST', "/billing/invoices/$id/pay")->json('data.payment_url'));

        // link pay_2 sudah dibayar tapi webhook hilang; link basi → jangan buat link baru, lunaskan
        $this->gw['pay_2']['status'] = 'completed';
        $this->travel(61)->minutes();
        $this->api('POST', "/billing/invoices/$id/pay")->assertStatus(409)->assertJsonPath('error.code', 'invoice_paid');
        $this->assertSame('paid', $this->invoice($id)->status);
        $this->assertSame(2, $this->created);
    }

    public function test_paid_without_amount_is_confirmed_with_gateway_or_goes_to_review(): void
    {
        $id = $this->renewal();
        // gateway mengonfirmasi lunas + nominal → diterima
        $this->gw['pay_1'] = ['status' => 'completed', 'amount' => 49000];
        $this->webhook(['payment_id' => 'pay_1', 'order_id' => $id, 'status' => 'completed'], 'evt_tanpa_nominal')->assertJsonPath('outcome', 'paid');

        $id2 = $this->renewal();
        // gateway belum mengonfirmasi → tidak lunas, masuk tinjauan
        $this->webhook(['payment_id' => 'pay_2', 'order_id' => $id2, 'status' => 'completed'], 'evt_tanpa_nominal_2')->assertJsonPath('outcome', 'rejected');
        $this->assertSame('issued', $this->invoice($id2)->status);
        $this->assertDatabaseHas('payment_reviews', ['kind' => 'amount_missing', 'invoice_id' => $this->invoice($id2)->id]);
    }

    public function test_second_payment_through_another_link_after_paid_goes_to_review(): void
    {
        $id = $this->renewal();
        $this->webhook(['payment_id' => 'pay_1', 'order_id' => $id, 'amount' => 49000, 'status' => 'completed'], 'evt_1')->assertJsonPath('outcome', 'paid');
        // pembayaran lain (link lama/baru) untuk invoice yang sama
        $this->webhook(['payment_id' => 'pay_lain', 'order_id' => "$id-r2", 'amount' => 49000, 'status' => 'completed'], 'evt_2')->assertJsonPath('outcome', 'rejected');
        $this->assertDatabaseHas('payment_reviews', ['kind' => 'duplicate_payment']);
        // kabar ulang untuk pembayaran yang sama (mis. cek status) bukan dobel
        $this->webhook(['payment_id' => 'pay_1', 'order_id' => $id, 'amount' => 49000, 'status' => 'completed'], 'evt_3')->assertJsonPath('outcome', 'ignored');
    }

    public function test_cancelled_subscription_invoice_paid_after_replacement_goes_to_review_without_changing_plan(): void
    {
        config(['payment.driver' => 'simulator']);
        $this->actingAs($this->makeUser());
        $old = $this->api('POST', '/billing/checkout', ['plan' => 'personal_pro', 'cycle' => 'yearly'])->json('data.invoice.id');
        $new = $this->api('POST', '/billing/checkout', ['plan' => 'personal_pro', 'cycle' => 'monthly'])->json('data.invoice.id');
        $this->assertSame('void', $this->invoice($old)->status);
        $pay = fn (string $id) => app(ApplyPayment::class)->handle(new PaymentNotification('qrisgw', 'evt-'.$id, $id, PaymentNotification::PAID,
            (int) $this->invoice($id)->total, 'QRIS', true, [], 'pay-'.$id));

        $this->assertSame('paid', $pay($new));
        $sub = Subscription::withoutGlobalScope(TenantScope::class)->where('tenant_id', $this->invoice($new)->tenant_id)->firstOrFail();
        $this->assertSame('monthly', $sub->cycle);
        $this->assertSame('rejected', $pay($old)); // tagihan dobel → tinjauan, paket tetap bulanan
        $this->assertSame('monthly', $sub->fresh()->cycle);
        $this->assertDatabaseHas('payment_reviews', ['kind' => 'paid_after_cancel', 'invoice_id' => $this->invoice($old)->id]);
    }

    public function test_cancelled_invoice_paid_late_without_replacement_still_activates(): void
    {
        config(['payment.driver' => 'simulator']);
        $this->actingAs($this->makeUser());
        $id = $this->api('POST', '/billing/checkout', ['plan' => 'personal_pro', 'cycle' => 'monthly'])->json('data.invoice.id');
        $this->invoice($id)->update(['status' => 'void']); // batas 30 menit lewat, link gateway masih hidup
        $outcome = app(ApplyPayment::class)->handle(new PaymentNotification('qrisgw', 'evt-late', $id, PaymentNotification::PAID, 49000, 'QRIS', true, [], 'pay-late'));
        $this->assertSame('paid', $outcome);
        $this->assertSame('personal_pro', $this->invoice($id)->tenant->fresh()->plan->key);
    }

    public function test_voucher_quota_is_atomic_and_open_invoices_reserve_it(): void
    {
        config(['payment.driver' => 'simulator']);
        $v = Voucher::create(['code' => 'SATU', 'kind' => 'percent', 'value' => 10, 'is_active' => true, 'max_redemptions' => 1, 'applies_to' => 'plan']);

        $this->actingAs($this->makeUser('a@example.com', 'A'));
        $a = $this->api('POST', '/billing/checkout', ['plan' => 'personal_pro', 'cycle' => 'monthly', 'voucher' => 'SATU'])->assertCreated()->json('data.invoice.id');
        // invoice A masih menunggu bayar → kuota 1 sudah dipesan
        $this->actingAs($this->makeUser('b@example.com', 'B'));
        $this->api('POST', '/billing/checkout', ['plan' => 'personal_pro', 'cycle' => 'monthly', 'voucher' => 'SATU'])->assertStatus(422)->assertJsonPath('error.code', 'voucher_invalid');

        // dua lunas bersamaan (mis. B checkout sebelum reservasi ada) → hitungan tidak melewati kuota diam-diam
        $b = $this->invoice($a)->replicate(['public_id', 'number', 'payment_ref']);
        $b->forceFill(['tenant_id' => User::where('email', 'b@example.com')->first()->personal_tenant_id, 'number' => 'INV/UJI/B'])->save();
        $pay = fn (Invoice $i) => app(ApplyPayment::class)->handle(new PaymentNotification('qrisgw', 'evt-'.$i->id, $i->number, PaymentNotification::PAID, (int) $i->total, 'QRIS', true, []));
        $this->assertSame('paid', $pay($this->invoice($a)));
        $this->assertSame('paid', $pay($b->fresh()));
        $this->assertSame(2, $v->fresh()->redeemed_count);
        $this->assertDatabaseHas('audit_logs', ['action' => 'voucher.over_quota']);
    }

    public function test_unverified_webhook_is_stored_without_payload_and_gateway_is_asked_once_per_minute(): void
    {
        $id = $this->renewal();
        $before = $this->created;
        for ($i = 0; $i < 3; $i++) {
            $this->webhook(['payment_id' => 'pay_1', 'order_id' => $id, 'amount' => 49000, 'status' => 'completed'], "evt_palsu_$i", 'kunci-salah')->assertOk();
        }
        $statusCalls = collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'GET')->count();
        $this->assertSame(1, $statusCalls);
        $this->assertSame($before, $this->created);
        $e = PaymentEvent::where('external_id', 'evt_palsu_0')->firstOrFail();
        $this->assertTrue($e->payload['unverified']);
        $this->assertArrayNotHasKey('data', $e->payload);
        $this->assertSame('issued', $this->invoice($id)->status);
    }

    public function test_simulator_only_runs_in_local_or_testing(): void
    {
        config(['payment.driver' => 'simulator']);
        $this->assertTrue(PaymentService::simulatorAllowed());
        $this->app['env'] = 'staging';
        try {
            $this->assertNull(PaymentService::info());
            $check = collect(Health::collect()['checks'])->firstWhere('label', 'Gateway pembayaran');
            $this->assertFalse($check['ok']);
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_reconcile_covers_renewal_invoices_older_than_48_hours_until_grace_end(): void
    {
        $id = $this->renewal();
        $this->travel(3)->days();
        $this->gw['pay_1'] = ['status' => 'completed', 'amount' => 49000];
        $this->artisan('tapcard:payments-reconcile')->expectsOutput('1 invoice dicek, 1 lunas.')->assertSuccessful();
        $this->assertSame('paid', $this->invoice($id)->status);
        $this->assertSame(0, PaymentReview::count());
    }
}
