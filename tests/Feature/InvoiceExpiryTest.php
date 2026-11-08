<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Invoice yang dibuat pengguna batal otomatis bila tidak dibayar dalam payment.invoice_ttl_minutes (30 menit). */
class InvoiceExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function api(string $method, string $path, array $data = [])
    {
        return $this->json($method, $this->host('app', "/api$path"), $data);
    }

    public function test_unpaid_checkout_expires_after_30_minutes_and_a_new_one_can_be_made(): void
    {
        $this->actingAs($this->makeUser());
        $c = $this->api('POST', '/billing/checkout', ['plan' => 'personal_pro', 'cycle' => 'monthly'])->assertCreated()->json('data.invoice');
        $this->assertEqualsWithDelta(now()->addMinutes(30)->timestamp, strtotime($c['due_at']), 5, 'berlaku 30 menit');
        $this->api('GET', '/billing')->assertJsonPath('data.open_invoice.id', $c['id']);

        $this->travel(29)->minutes();
        $this->artisan('tapcard:expire-invoices')->assertSuccessful();
        $this->assertSame('issued', Invoice::withoutGlobalScope(TenantScope::class)->where('public_id', $c['id'])->value('status'));

        $this->travel(2)->minutes();
        // belum dibatalkan scheduler pun sudah tidak ditawarkan / tidak bisa dibayar
        $this->api('GET', '/billing')->assertJsonPath('data.open_invoice', null);
        $this->api('POST', "/billing/invoices/{$c['id']}/pay")->assertJsonPath('error.code', 'invoice_not_payable');

        $this->artisan('tapcard:expire-invoices')->assertSuccessful();
        $this->assertSame('void', Invoice::withoutGlobalScope(TenantScope::class)->where('public_id', $c['id'])->value('status'));
        $again = $this->api('POST', '/billing/checkout', ['plan' => 'personal_pro', 'cycle' => 'monthly'])->assertCreated()->json('data.invoice.id');
        $this->assertNotSame($c['id'], $again);
    }

    public function test_renewal_invoices_without_user_never_expire_this_way(): void
    {
        $rina = $this->makeUser();
        $inv = Invoice::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $rina->personal_tenant_id, 'number' => 'INV/R/1', 'type' => 'subscription', 'status' => 'issued',
            'lines' => [], 'subtotal' => 49000, 'total' => 49000, 'issued_at' => now(), 'due_at' => now()->addDays(7),
        ]);
        $this->travel(2)->days();
        $this->artisan('tapcard:expire-invoices')->assertSuccessful();
        $this->assertSame('issued', $inv->fresh()->status);
        $this->assertTrue($inv->fresh()->isPayable());
    }
}
