<?php

namespace Tests\Feature;

use App\Models\Design;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Voucher;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Keranjang cetak (satuan & paket), ID badge sebagai produk sendiri, kode promo pesanan, ringkasan harga server. */
class CartAndPromoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const SHIP = ['method' => 'regular', 'recipient_name' => 'Rina', 'recipient_phone' => '081234567890', 'province' => 'Jawa Barat',
        'city' => 'Kota Bandung', 'district' => 'Coblong', 'address_line' => 'Jl. Dago No. 10, RT 01/RW 02', 'postal_code' => '40135'];

    private function api(string $method, string $path, array $data = [])
    {
        return $this->json($method, $this->host('app', "/api$path"), $data);
    }

    /** kartu lanskap, kartu potret berlubang lanyard + lanyard pasangannya — semuanya siap cetak */
    private function designs(): array
    {
        $this->actingAs($this->makeUser());
        $card = $this->api('POST', '/designs', ['template' => 'onyx', 'orientation' => 'landscape'])->assertCreated()->json('data.id');
        $badge = $this->api('POST', '/designs', ['template' => 'onyx', 'orientation' => 'portrait', 'lanyard' => true])->assertCreated()->json('data.id');
        $lanyard = $this->api('POST', "/designs/$badge/lanyard")->assertCreated()->json('data.id');
        Design::withoutGlobalScope(TenantScope::class)->whereIn('public_id', [$card, $badge, $lanyard])->update(['validated_at' => now()]);

        return compact('card', 'badge', 'lanyard');
    }

    public function test_cart_adds_items_and_bundles_with_default_products(): void
    {
        $d = $this->designs();
        $this->api('POST', '/cart', ['design' => $d['card']])->assertCreated();
        $cart = $this->api('POST', '/cart', ['design' => $d['badge'], 'with_lanyard' => true])->assertCreated()->json('data');
        $this->assertSame(['pvc_ntag215', 'id_badge_ntag215', null], array_column($cart, 'product'), 'potret berlubang lanyard → ID badge');
        $this->assertSame(['card', 'card', 'lanyard'], array_column($cart, 'kind'));
        $this->assertGreaterThanOrEqual($cart[2]['min_qty'], $cart[2]['quantity'], 'lanyard langsung memenuhi minimal pesan');

        // menambah lagi = menambah jumlah, bukan baris baru
        $cart = $this->api('POST', '/cart', ['design' => $d['card'], 'quantity' => 4])->json('data');
        $this->assertSame(5, $cart[0]['quantity']);

        // ID badge tidak bisa untuk desain lanskap
        $this->api('PATCH', "/cart/{$cart[0]['id']}", ['product' => 'id_badge_ntag215'])->assertStatus(422)->assertJsonPath('error.code', 'id_badge_design');
        // ganti produk ke yang sudah ada di keranjang → digabung
        $this->api('POST', '/cart', ['design' => $d['badge'], 'product' => 'pvc_ntag215', 'quantity' => 2])->assertCreated();
        $cart = $this->api('GET', '/cart')->json('data');
        $plain = collect($cart)->first(fn ($i) => $i['design']['id'] === $d['badge'] && $i['product'] === 'pvc_ntag215');
        $merged = $this->api('PATCH', "/cart/{$plain['id']}", ['product' => 'id_badge_ntag215'])->assertOk()->json('data');
        $this->assertSame(3, collect($merged)->firstWhere('product', 'id_badge_ntag215')['quantity']);
        $this->assertCount(3, $merged);

        // kartu tanpa lanyard pasangan tidak bisa dipaketkan; desain belum siap ditolak
        $this->api('POST', '/cart', ['design' => $d['card'], 'with_lanyard' => true])->assertJsonPath('error.code', 'no_paired_lanyard');
        Design::withoutGlobalScope(TenantScope::class)->where('public_id', $d['card'])->update(['validated_at' => null]);
        $this->api('POST', '/cart', ['design' => $d['card']])->assertJsonPath('error.code', 'design_not_ready');
        $this->api('DELETE', "/cart/{$merged[0]['id']}")->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_promo_quote_and_checkout_selected_cart_items(): void
    {
        $d = $this->designs();
        Voucher::create(['code' => 'KARTU10', 'kind' => 'percent', 'value' => 10, 'applies_to' => 'order', 'is_active' => true]);
        Voucher::create(['code' => 'PAKETONLY', 'kind' => 'amount', 'value' => 5000, 'applies_to' => 'plan', 'is_active' => true]);
        $this->api('POST', '/cart', ['design' => $d['card'], 'quantity' => 2])->assertCreated();
        $cart = $this->api('POST', '/cart', ['design' => $d['badge'], 'with_lanyard' => true])->json('data');
        $items = [['kind' => 'card', 'design' => $d['card'], 'product' => 'pvc_ntag215', 'quantity' => 2]];

        $q = $this->api('POST', '/orders/quote', ['items' => $items, 'method' => 'regular'])->assertOk()->json('data');
        $this->assertSame([130000, 0], [$q['subtotal'], $q['discount']]);
        $this->api('POST', '/orders/quote', ['items' => $items, 'method' => 'regular', 'promo' => 'PAKETONLY'])->assertStatus(422)
            ->assertJsonPath('error.fields.promo.0', 'Kode promo tidak ditemukan.');
        $q = $this->api('POST', '/orders/quote', ['items' => $items, 'method' => 'regular', 'promo' => 'kartu10'])->assertOk()->json('data');
        $this->assertSame([13000, $q['subtotal'] + $q['shipping'] - 13000, 'KARTU10'], [$q['discount'], $q['total'], $q['promo']['code']]);

        // pesan hanya kartu (item keranjang pertama); ID badge + lanyard tetap di keranjang
        $o = $this->api('POST', '/orders', ['items' => $items, 'shipping' => self::SHIP, 'promo' => 'KARTU10', 'cart' => [$cart[0]['id']]])->assertCreated()->json('data.order');
        $this->assertSame([13000, 'KARTU10', 'Coblong'], [$o['discount'], $o['promo'], $o['shipping']['district']]);
        $this->assertSame($o['subtotal'] + $o['shipping_fee'] - 13000, $o['total']);
        $this->assertCount(2, $this->api('GET', '/cart')->json('data'));
        $inv = Invoice::withoutGlobalScopes()->where('order_id', Order::withoutGlobalScopes()->where('public_id', $o['id'])->value('id'))->first();
        $this->assertSame([13000, $o['total']], [(int) $inv->discount, (int) $inv->total]);
        $this->assertSame(-13000, collect($inv->lines)->firstWhere('code', 'discount')['amount']);
    }

    public function test_id_badge_order_needs_portrait_lanyard_design(): void
    {
        $d = $this->designs();
        $this->api('POST', '/orders', ['items' => [['kind' => 'card', 'design' => $d['card'], 'product' => 'id_badge_ntag215', 'quantity' => 1]], 'shipping' => self::SHIP])
            ->assertJsonPath('error.code', 'id_badge_design');
        $o = $this->api('POST', '/orders', ['items' => [
            ['kind' => 'card', 'design' => $d['badge'], 'product' => 'id_badge_ntag215', 'quantity' => 10],
            ['kind' => 'lanyard', 'design' => $d['lanyard'], 'quantity' => 10],
        ], 'shipping' => self::SHIP])->assertCreated()->json('data.order');
        $this->assertStringStartsWith('ID Badge PVC NFC', $o['items'][0]['label']);
        $this->assertSame(600000, $o['items'][0]['total']);
    }
}
