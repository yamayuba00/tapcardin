<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Halaman bayar & kuitansi: batas waktu dari jam server, status saat dicek, ringkasan paket aktif setelah lunas, akses. */
class PaymentClarityTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function api(string $method, string $path, array $data = [])
    {
        return $this->json($method, $this->host('app', "/api$path"), $data);
    }

    public function test_invoice_exposes_countdown_status_and_active_period_after_payment(): void
    {
        $rina = $this->makeUser();
        $this->actingAs($rina);
        $id = $this->api('POST', '/billing/checkout', ['plan' => 'personal_pro', 'cycle' => 'monthly'])->assertCreated()->json('data.invoice.id');

        $inv = $this->api('GET', "/billing/invoices/$id")->assertOk()->json('data');
        $this->assertSame('issued', $inv['status']);
        $this->assertNotNull($inv['expires_at']);
        $this->assertNotNull($inv['server_now']);
        $this->assertGreaterThan(now()->addMinutes(20)->timestamp, strtotime($inv['expires_at']));
        $this->assertNull($inv['active_period']);
        $this->assertSame('/billing', $inv['renew_to']);

        // cek status (dipakai halaman bayar tiap 4 dtk) — masih menunggu
        $this->api('POST', "/billing/invoices/$id/check")->assertOk()->assertJsonPath('data.status', 'issued');
        $this->api('POST', "/billing/invoices/$id/simulate", ['result' => 'paid', 'method' => 'QRIS'])->assertOk();

        $paid = $this->api('GET', "/billing/invoices/$id")->assertOk()->json('data');
        $this->assertSame('paid', $paid['status']);
        $this->assertNull($paid['expires_at'], 'lunas: tidak ada hitung mundur');
        $this->assertSame('active', $paid['active_period']['status']);
        $this->assertGreaterThanOrEqual(28, $paid['active_period']['days_left']);

        // kuitansi milik workspace lain tidak bisa dibuka
        $this->actingAs($this->makeUser('budi@example.com', 'Budi'));
        $this->api('GET', "/billing/invoices/$id")->assertNotFound();
    }
}
