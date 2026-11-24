<?php

namespace Tests\Feature;

use App\Models\Design;
use App\Models\Order;
use App\Models\ShippingZoneRate;
use App\Support\Indonesia;
use App\Support\Shipping\DummyShippingProvider;
use App\Support\Shipping\ZoneMap;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Ongkir per zona & pelacakan simulasi (penyedia dummy, bentuk sama dengan API kurir). */
class ShippingDummyTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function api(string $method, string $path, array $data = [])
    {
        return $this->json($method, $this->host('app', "/api$path"), $data);
    }

    private function card(): string
    {
        $id = $this->api('POST', '/designs', ['template' => 'onyx'])->assertCreated()->json('data.id');
        Design::withoutGlobalScope(TenantScope::class)->where('public_id', $id)->update(['validated_at' => now()]);

        return $id;
    }

    private function quote(string $design, array $over = []): TestResponse
    {
        return $this->api('POST', '/orders/quote', $over + [
            'items' => [['kind' => 'card', 'design' => $design, 'product' => 'pvc_ntag215', 'quantity' => 2]],
            'method' => 'kurir', 'province' => 'Jawa Barat', 'courier' => 'JNE', 'service' => 'REG',
        ]);
    }

    public function test_every_province_maps_to_exactly_one_zone(): void
    {
        $this->assertCount(38, Indonesia::PROVINCES);
        foreach (Indonesia::PROVINCES as $province) {
            $zone = ZoneMap::zoneOf($province);
            $this->assertNotNull($zone, $province);
            $this->assertContains($zone, ZoneMap::ZONES);
        }
        $this->assertNull(ZoneMap::zoneOf('Atlantis'));
    }

    public function test_cost_is_base_plus_weight_rounded_up_per_kg(): void
    {
        $rate = ShippingZoneRate::where('zone_code', 'Jawa-Bali')->where('courier', 'JNE')->where('service', 'REG')->firstOrFail();
        $offer = collect((new DummyShippingProvider)->cost('Jawa-Bali', 'Jawa Barat', 2500, 'JNE'))->firstWhere('service', 'REG');
        $this->assertSame($rate->base_cost + 3 * $rate->per_kg_cost, $offer['cost'], '2,5 kg dibulatkan ke 3 kg');
        $this->assertTrue($rate->is_placeholder);
    }

    public function test_tracking_is_deterministic_per_awb_and_marked_simulated(): void
    {
        $p = new DummyShippingProvider;
        $a = $p->track('JNE123456', 'JNE');
        $this->assertSame($a, $p->track('JNE123456', 'JNE'));
        $this->assertTrue($a['simulated']);
        $this->assertContains($a['status'], ['accepted', 'picked_up', 'in_transit', 'delivered']);
        $this->assertSame($a['status'], $a['history'][0]['status'], 'riwayat terbaru di depan');
    }

    public function test_quote_uses_provider_cost_and_unknown_province_is_rejected(): void
    {
        $this->actingAs($this->makeUser());
        $card = $this->card();
        $rate = ShippingZoneRate::where('zone_code', 'Jawa-Bali')->where('courier', 'JNE')->where('service', 'REG')->firstOrFail();
        $grams = 2 * (int) config('services.shipping.weight_gram_per_item');
        $expected = $rate->base_cost + max(1, (int) ceil($grams / 1000)) * $rate->per_kg_cost;

        $this->quote($card)->assertOk()->assertJsonPath('data.shipping', $expected)->assertJsonPath('data.courier', 'JNE')->assertJsonPath('data.service', 'REG');
        $this->quote($card, ['province' => 'Atlantis'])->assertUnprocessable()->assertJsonPath('error.code', 'shipping_province');
    }

    public function test_courier_quote_is_refused_in_production_with_dummy_driver(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $card = $this->card();
        $tenant = $user->personalTenant;
        $this->app['env'] = 'production';
        try {
            app(\App\Actions\Orders\CreateOrder::class)->quote($tenant, $user, [['kind' => 'card', 'design' => $card, 'product' => 'pvc_ntag215', 'quantity' => 2]],
                'kurir', null, ['province' => 'Jawa Barat', 'courier' => 'JNE', 'service' => 'REG']);
            $this->fail('harus ditolak di produksi');
        } catch (\App\Exceptions\ApiError $e) {
            $this->assertSame(503, $e->status);
            $this->assertSame("shipping_unavailable", $e->errorCode);
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_order_stores_courier_and_only_owner_reads_tracking(): void
    {
        $rina = $this->makeUser();
        $this->actingAs($rina);
        $card = $this->card();
        $res = $this->api('POST', '/orders', [
            'items' => [['kind' => 'card', 'design' => $card, 'product' => 'pvc_ntag215', 'quantity' => 2]],
            'shipping' => ['method' => 'kurir', 'courier' => 'JNE', 'service' => 'REG', 'recipient_name' => 'Rina', 'recipient_phone' => '081234567890',
                'address_line' => 'Jl. Sudirman No. 1, RT 02/RW 03', 'district' => 'Coblong', 'city' => 'Kota Bandung', 'province' => 'Jawa Barat', 'postal_code' => '40135'],
        ])->assertCreated();
        $this->assertSame('JNE', $res->json('data.order.shipping.courier'));
        $id = $res->json('data.order.id');
        Order::withoutGlobalScopes()->where('public_id', $id)->update(['courier' => 'JNE', 'tracking_number' => 'JNE555']);

        $this->api('GET', "/orders/$id/tracking")->assertOk()->assertJsonPath('data.simulated', true)->assertJsonPath('data.awb', 'JNE555');
        $this->actingAs($this->makeUser('lain@example.com', 'Lain'));
        $this->api('GET', "/orders/$id/tracking")->assertNotFound();
    }
}
