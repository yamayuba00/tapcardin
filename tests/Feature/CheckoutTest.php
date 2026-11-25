<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Checkout;
use App\Models\Design;
use App\Models\Order;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Draf checkout wizard: pilihan disimpan di server, pesanan dibuat sekali saat bayar, draf milik orang lain & kedaluwarsa ditolak. */
class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const SHIP = ['method' => 'regular', 'recipient_name' => 'Rina', 'recipient_phone' => '081234567890', 'province' => 'Jawa Barat',
        'city' => 'Kota Bandung', 'district' => 'Coblong', 'address_line' => 'Jl. Dago No. 10, RT 01/RW 02', 'postal_code' => '40135'];

    private function api(string $method, string $path, array $data = [])
    {
        return $this->json($method, $this->host('app', "/api$path"), $data);
    }

    private function designs(): array
    {
        $this->actingAs($this->makeUser());
        $card = $this->api('POST', '/designs', ['template' => 'onyx', 'orientation' => 'landscape'])->assertCreated()->json('data.id');
        $badge = $this->api('POST', '/designs', ['template' => 'onyx', 'orientation' => 'portrait', 'lanyard' => true])->assertCreated()->json('data.id');
        $lanyard = $this->api('POST', "/designs/$badge/lanyard")->assertCreated()->json('data.id');
        Design::withoutGlobalScope(TenantScope::class)->whereIn('public_id', [$card, $badge, $lanyard])->update(['validated_at' => now()]);

        return compact('card', 'badge', 'lanyard');
    }

    public function test_draft_keeps_selection_and_pays_once(): void
    {
        $d = $this->designs();
        $this->api('POST', '/cart', ['design' => $d['card'], 'quantity' => 2])->assertCreated();
        $this->api('POST', '/cart', ['design' => $d['badge'], 'with_lanyard' => true])->assertCreated();
        $cartIds = CartItem::pluck('public_id')->all();

        $draft = $this->api('POST', '/checkouts', ['cart' => $cartIds])->assertCreated()->json('data');
        $this->assertSame($cartIds, $draft['cart']);
        $this->assertSame('open', $draft['status']);

        $this->api('PATCH', "/checkouts/{$draft['id']}", ['shipping' => self::SHIP, 'promo' => null])->assertOk()
            ->assertJsonPath('data.shipping.city', 'Kota Bandung');

        $paid = $this->api('POST', "/checkouts/{$draft['id']}/pay")->assertCreated()->json('data');
        $this->assertNotEmpty($paid['payment_url']);
        $this->assertSame(0, CartItem::count(), 'item keranjang yang dibayar dihapus');
        $this->assertSame(1, Order::count());

        $again = $this->api('POST', "/checkouts/{$draft['id']}/pay")->assertOk()->json('data');
        $this->assertSame($paid['order']['id'], $again['order']['id'], 'bayar ulang tidak membuat pesanan kedua');
        $this->assertSame(1, Order::count());
        $this->api('GET', "/checkouts/{$draft['id']}")->assertJsonPath('data.status', 'paid');
    }

    public function test_pay_needs_shipping_and_items(): void
    {
        $d = $this->designs();
        $this->api('POST', '/cart', ['design' => $d['card']])->assertCreated();
        $draft = $this->api('POST', '/checkouts')->assertCreated()->json('data');

        $this->api('POST', "/checkouts/{$draft['id']}/pay")->assertStatus(422)->assertJsonPath('error.code', 'checkout_shipping');

        $this->api('PATCH', "/checkouts/{$draft['id']}", ['cart' => [], 'shipping' => self::SHIP])->assertOk();
        $this->api('POST', "/checkouts/{$draft['id']}/pay")->assertStatus(422)->assertJsonPath('error.code', 'checkout_empty');
        $this->assertSame(1, CartItem::count(), 'keranjang belum berubah');
    }

    public function test_draft_of_another_user_is_not_found(): void
    {
        $d = $this->designs();
        $this->api('POST', '/cart', ['design' => $d['card']])->assertCreated();
        $draft = $this->api('POST', '/checkouts')->assertCreated()->json('data');

        $this->actingAs($this->makeUser('lain@example.com', 'Budi Lain'));
        $this->api('GET', "/checkouts/{$draft['id']}")->assertStatus(404)->assertJsonPath('error.code', 'checkout_not_found');
    }

    public function test_expired_draft_is_gone(): void
    {
        $d = $this->designs();
        $this->api('POST', '/cart', ['design' => $d['card']])->assertCreated();
        $draft = $this->api('POST', '/checkouts')->assertCreated()->json('data');
        Checkout::withoutGlobalScopes()->update(['expires_at' => now()->subMinute()]);

        $this->api('GET', "/checkouts/{$draft['id']}")->assertStatus(410)->assertJsonPath('error.code', 'checkout_expired');
    }
}
