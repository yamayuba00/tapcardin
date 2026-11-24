<?php

namespace Tests\Feature;

use App\Actions\Orders\CreateOrder;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Pesanan: pemesan menandai diterima, dan validasi alamat (kode pos, kota, kecamatan). */
class OrderOpsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function shipped(User $user): Order
    {
        $tenant = $user->personalTenant;

        return Order::withoutGlobalScopes()->create([
            'public_id' => (string) Str::ulid(), 'number' => 'ORD/2026/11/'.random_int(100000, 999999),
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'status' => 'shipped', 'subtotal' => 130000, 'shipping_fee' => 20000,
            'total' => 150000, 'shipped_at' => now(), 'courier' => 'JNE', 'tracking_number' => 'JNE123',
        ]);
    }

    public function test_customer_confirms_receipt_only_after_shipping_and_only_own_order(): void
    {
        $rina = $this->makeUser();
        $order = $this->shipped($rina);

        $this->actingAs($this->makeUser('lain@example.com', 'Lain'));
        $this->json('POST', $this->host('app', "/api/orders/{$order->public_id}/received"))->assertNotFound();

        $this->actingAs($rina);
        $this->json('POST', $this->host('app', "/api/orders/{$order->public_id}/received"))->assertOk()
            ->assertJsonPath('data.status', 'delivered');
        $fresh = $order->fresh();
        $this->assertNotNull($fresh->delivered_at);
        $this->assertDatabaseHas('notifications', ['user_id' => $rina->id, 'type' => 'order_status']);

        $pending = Order::withoutGlobalScopes()->create(['public_id' => (string) Str::ulid(), 'number' => 'ORD/2026/11/000001',
            'tenant_id' => $rina->personal_tenant_id, 'user_id' => $rina->id, 'status' => 'pending_payment', 'subtotal' => 1, 'total' => 1]);
        $this->json('POST', $this->host('app', "/api/orders/{$pending->public_id}/received"))->assertStatus(422)
            ->assertJsonPath('error.code', 'order_not_shipped');
    }

    public function test_postal_code_must_be_five_digits_not_leading_zero_and_match_jakarta_range(): void
    {
        $ok = ['recipient_name' => 'Rina Saputra', 'recipient_phone' => '0812 3456 7890', 'province' => 'Jawa Barat', 'city' => 'Bandung',
            'district' => 'Coblong', 'address_line' => 'Jl. Dago No. 12 RT 01/02', 'postal_code' => '40135'];
        CreateOrder::validateAddress($ok);
        $this->addToAssertionCount(1);

        foreach (['01234', '4013', '401356', 'abcde'] as $bad) {
            $this->assertInvalidAddress([...$ok, 'postal_code' => $bad], 'shipping.postal_code');
        }
        $this->assertInvalidAddress([...$ok, 'province' => 'DKI Jakarta', 'postal_code' => '40135'], 'shipping.postal_code');
        CreateOrder::validateAddress([...$ok, 'province' => 'DKI Jakarta', 'postal_code' => '12190']);
        $this->addToAssertionCount(1);
    }

    public function test_city_and_district_reject_control_characters_and_overlong_text(): void
    {
        $ok = ['recipient_name' => 'Rina Saputra', 'recipient_phone' => '0812 3456 7890', 'province' => 'Jawa Barat', 'city' => 'Bandung',
            'district' => 'Coblong', 'address_line' => 'Jl. Dago No. 12 RT 01/02', 'postal_code' => '40135'];
        $this->assertInvalidAddress([...$ok, 'city' => "Bandung\nDROP"], 'shipping.city');
        $this->assertInvalidAddress([...$ok, 'district' => str_repeat('A', 61)], 'shipping.district');
    }

    private function assertInvalidAddress(array $ship, ?string $field): void
    {
        try {
            CreateOrder::validateAddress($ship);
            $this->fail('alamat seharusnya ditolak: '.json_encode($ship));
        } catch (ValidationException $e) {
            if ($field !== null) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }
    }
}
