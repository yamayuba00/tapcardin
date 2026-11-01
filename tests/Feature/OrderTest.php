<?php

namespace Tests\Feature;

use App\Enums\CardStatus;
use App\Models\Batch;
use App\Models\Card;
use App\Models\Design;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PlatformStaff;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Pemesanan kartu fisik & lanyard: pesan → bayar (payment service) → produksi (batch) → kirim → aktivasi. */
class OrderTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function app_(string $method, string $path, array $data = [])
    {
        return $this->json($method, $this->host('app', "/api$path"), $data);
    }

    private function panel(string $method, string $path, array $data = [])
    {
        return $this->json($method, $this->host('panel', "/api$path"), $data);
    }

    private function staff(string $role): User
    {
        $u = $this->makeUser("$role@tapcard.test", 'Staf');
        PlatformStaff::create(['user_id' => $u->id, 'role_id' => Role::system($role)->id, 'status' => 'active', 'last_reverified_at' => now()]);

        return $u;
    }

    /** Desain kartu & lanyard milik pengguna yang sudah lolos cek cetak. @return array{card:string, lanyard:string} */
    private function designs(User $u): array
    {
        $this->actingAs($u);
        $card = $this->app_('POST', '/designs', ['template' => 'onyx'])->assertCreated()->json('data.id');
        $lanyard = $this->app_('POST', '/designs', ['kind' => 'lanyard'])->assertCreated()->json('data.id');
        Design::withoutGlobalScope(TenantScope::class)->whereIn('public_id', [$card, $lanyard])->update(['validated_at' => now()]);

        return ['card' => $card, 'lanyard' => $lanyard];
    }

    private function order(array $d, array $over = [])
    {
        return $this->app_('POST', '/orders', $over + [
            'items' => [
                ['kind' => 'card', 'design' => $d['card'], 'product' => 'pvc_ntag215', 'quantity' => 2],
                ['kind' => 'lanyard', 'design' => $d['lanyard'], 'quantity' => 50],
            ],
            'shipping' => ['method' => 'regular', 'recipient_name' => 'Rina', 'recipient_phone' => '08123', 'address_line' => 'Jl. Sudirman 1', 'city' => 'Jakarta', 'province' => 'DKI', 'postal_code' => '10210'],
        ]);
    }

    public function test_full_flow_order_pay_produce_ship_and_activate(): void
    {
        Storage::fake('local');
        $rina = $this->makeUser();
        $d = $this->designs($rina);

        $o = $this->order($d)->assertCreated()->json('data');
        $this->assertSame('pending_payment', $o['order']['status']);
        $this->assertMatchesRegularExpression('#^ORD/\d{4}/\d{2}/\d{6}$#', $o['order']['number']);
        $this->assertSame(2 * 65000, $o['order']['items'][0]['total'], 'harga kartu dari katalog server');
        $this->assertSame(20000, $o['order']['shipping_fee']);
        $this->assertSame($o['order']['subtotal'] + 20000, $o['order']['total']);
        $this->assertStringEndsWith('/billing/pay/'.Invoice::withoutGlobalScope(TenantScope::class)->where('type', 'order')->value('public_id'), $o['payment_url']);
        $this->assertArrayHasKey('front', $o['order']['items'][0]['design'], 'desain dibekukan sebagai snapshot');

        // bayar lewat jalur yang sama dengan langganan → pesanan dibayar, langganan tidak tersentuh
        $inv = Invoice::withoutGlobalScope(TenantScope::class)->where('type', 'order')->firstOrFail();
        $this->assertSame($o['order']['total'], (int) $inv->total);
        $this->app_('GET', "/billing/invoices/{$inv->public_id}")->assertJsonPath('data.return_to', "/orders/{$o['order']['id']}");
        $this->app_('POST', "/billing/invoices/{$inv->public_id}/simulate", ['result' => 'paid', 'method' => 'QRIS'])->assertJsonPath('data.status', 'paid');
        $this->app_('GET', "/orders/{$o['order']['id']}")->assertJsonPath('data.status', 'paid');
        $this->assertSame(0, Subscription::withoutGlobalScope(TenantScope::class)->count());
        $this->assertDatabaseHas('notifications', ['user_id' => $rina->id, 'type' => 'order_status']);
        $this->app_('POST', "/orders/{$o['order']['id']}/cancel")->assertJsonPath('error.code', 'order_not_cancellable');

        // akun baru + nilai besar → bertanda risiko: produksi diblokir sampai ditinjau
        $this->actingAs($this->staff('platform_owner'));
        $this->panel('GET', '/orders?status=paid')->assertOk()->assertJsonPath('meta.total', 1);
        $this->panel('GET', "/orders/{$o['order']['id']}")->assertJsonPath('data.review.status', 'pending')->assertJsonPath('data.review.flags.0.code', 'new_account');
        $this->panel('POST', "/orders/{$o['order']['id']}/production")->assertStatus(409)->assertJsonPath('error.code', 'review_required');
        $this->panel('POST', "/orders/{$o['order']['id']}/review", ['decision' => 'approve', 'note' => 'Desain & alamat wajar'])->assertOk()->assertJsonPath('data.review.status', 'approved');

        // panel: mulai produksi → 1 batch untuk item kartu, masuk workspace pemesan
        $p = $this->panel('POST', "/orders/{$o['order']['id']}/production")->assertOk()->assertJsonPath('data.status', 'in_production')->json('data');
        $this->assertCount(1, $p['batches']);
        $batch = Batch::where('public_id', $p['batches'][0]['id'])->firstOrFail();
        $this->assertSame([2, $rina->id, $rina->personal_tenant_id], [$batch->quantity, $batch->holder_user_id, $batch->tenant_id]);
        $this->panel('GET', "/batches/{$batch->public_id}")->assertJsonPath('data.order.number', $o['order']['number']);
        $this->panel('POST', "/orders/{$o['order']['id']}/ship", ['tracking_number' => 'JNE1'])->assertJsonPath('error.code', 'nothing_to_ship');

        // token → encode → QC → kirim pesanan
        $this->panel('POST', "/batches/{$batch->public_id}/generate")->assertOk();
        $cards = Card::acrossTenants()->where('batch_id', $batch->id)->orderBy('sequence')->get();
        $this->assertSame($p['items'][0]['id'], OrderItem::find($cards[0]->order_item_id)->public_id, 'kartu tertaut ke item pesanan');
        foreach ($cards as $i => $c) {
            $this->panel('POST', "/cards/{$c->card_token}/encoded", ['uid' => '04A1B2C3D4E5F'.$i])->assertOk();
            $this->panel('POST', "/cards/{$c->card_token}/qc", ['nfc_url' => $c->resolverUrl(), 'qr_url' => $c->qrUrl()])->assertJsonPath('data.passed', true);
        }
        $this->panel('POST', "/orders/{$o['order']['id']}/ship", [])->assertJsonPath('error.code', 'tracking_required');
        $this->panel('POST', "/orders/{$o['order']['id']}/ship", ['courier' => 'JNE', 'tracking_number' => 'JNE123'])->assertOk()->assertJsonPath('data.status', 'shipped');
        $this->assertSame(CardStatus::Unclaimed, $cards[0]->fresh()->status);
        $this->panel('POST', "/orders/{$o['order']['id']}/deliver")->assertOk()->assertJsonPath('data.status', 'delivered');

        // pemesan mengaktifkan kartu tanpa kode (dia pembelinya)
        $this->actingAs($rina);
        $this->app_('POST', '/cards/activate', ['token' => $cards[0]->card_token])->assertOk();
        $this->app_('GET', "/orders/{$o['order']['id']}")->assertJsonPath('data.shipping.tracking_number', 'JNE123');
    }

    public function test_validation_prices_from_server_and_ownership(): void
    {
        $rina = $this->makeUser();
        $d = $this->designs($rina);
        Design::withoutGlobalScope(TenantScope::class)->where('public_id', $d['card'])->update(['validated_at' => null]);
        $this->order($d)->assertJsonPath('error.code', 'design_not_ready');
        Design::withoutGlobalScope(TenantScope::class)->where('public_id', $d['card'])->update(['validated_at' => now()]);

        $this->order($d, ['items' => [['kind' => 'lanyard', 'design' => $d['lanyard'], 'quantity' => 3]], 'shipping' => ['method' => 'pickup']])
            ->assertJsonPath('error.code', 'quantity_too_low');
        $this->order($d, ['shipping' => ['method' => 'regular', 'recipient_name' => 'Rina']])->assertJsonPath('error.code', 'address_incomplete');
        $pickup = $this->order($d, ['items' => [['kind' => 'card', 'design' => $d['card'], 'product' => 'pvc_ntag215', 'quantity' => 1, 'unit_price' => 1]], 'shipping' => ['method' => 'pickup']])
            ->assertCreated()->json('data.order');
        $this->assertSame(65000, $pickup['total'], 'harga dari klien diabaikan; ambil sendiri tanpa ongkir');

        // desain orang lain tidak bisa dipesan; pesanan orang lain tidak terlihat
        $lain = $this->makeUser('lain@example.com', 'Lain');
        $this->actingAs($lain);
        $this->order($d)->assertNotFound();
        $this->app_('GET', "/orders/{$pickup['id']}")->assertNotFound();
        $this->assertSame(0, $this->app_('GET', '/orders')->json('meta.total'));

        // pemesan membatalkan sebelum bayar → invoice void
        $this->actingAs($rina);
        $this->app_('POST', "/orders/{$pickup['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('void', Invoice::withoutGlobalScope(TenantScope::class)->where('order_id', Order::withoutGlobalScope(TenantScope::class)->where('public_id', $pickup['id'])->value('id'))->value('status'));
    }

    public function test_order_payment_through_link_gateway_returns_to_order_page(): void
    {
        config(['payment.driver' => 'link', 'payment.gateway' => [
            'name' => 'QRIS', 'code' => 'qris', 'api_url' => 'https://pay.test/api/v1', 'api_key' => 'k', 'api_key_header' => 'X-Api-Key',
            'create_path' => '/payments', 'status_path' => '/payments/{id}', 'method' => 'QRIS', 'expires_hours' => 24, 'timeout' => 5,
            'webhook' => ['verify' => 'none'],
        ]]);
        Http::fake([
            'pay.test/api/v1/payments' => Http::response(['payment_id' => 'pay_o1', 'payment_link_url' => 'https://pay.test/link/pay_o1']),
            'pay.test/api/v1/payments/pay_o1' => Http::response(['status' => 'completed', 'amount' => 65000]),
        ]);
        $rina = $this->makeUser();
        $d = $this->designs($rina);
        $o = $this->order($d, ['items' => [['kind' => 'card', 'design' => $d['card'], 'product' => 'pvc_ntag215', 'quantity' => 1]], 'shipping' => ['method' => 'pickup']])
            ->assertCreated()->json('data');
        $this->assertSame('https://pay.test/link/pay_o1', $o['payment_url']);
        Http::assertSent(fn ($r) => str_ends_with((string) ($r['success_return_url'] ?? ''), "/orders/{$o['order']['id']}?paid=1") && $r['amount'] === 65000);

        $this->app_('POST', "/orders/{$o['order']['id']}/check")->assertOk()->assertJsonPath('data.status', 'paid');
    }

    public function test_panel_catalog_manages_card_products_and_shipping(): void
    {
        $this->actingAs($this->staff('platform_owner'));
        $c = $this->panel('GET', '/catalog')->assertOk()->json('data');
        $this->assertSame('pvc_ntag215', $c['card_products'][0]['key']);
        $this->panel('PATCH', "/catalog/card-products/{$c['card_products'][0]['public_id']}", ['price' => 70000])->assertOk();
        $this->panel('PATCH', "/catalog/shipping/{$c['shipping_methods'][0]['public_id']}", ['price' => 25000])->assertOk();
        $this->assertDatabaseHas('card_products', ['key' => 'pvc_ntag215', 'price' => 70000]);
        $this->assertDatabaseHas('shipping_methods', ['key' => 'regular', 'price' => 25000]);
    }
}
