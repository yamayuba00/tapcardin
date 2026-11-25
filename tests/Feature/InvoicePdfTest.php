<?php

namespace Tests\Feature;

use App\Enums\TenantType;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Tagihan sebagai PDF di server: unduhan hanya untuk workspace pemilik tagihan dan pengguna yang berhak. */
class InvoicePdfTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Tenant $org;

    private User $owner;

    private User $budi;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->owner = $this->makeUser('owner@majujaya.id', 'Owner MJ');
        $this->org = Tenant::create([
            'type' => TenantType::Company, 'name' => 'Maju Jaya', 'slug' => 'majujaya', 'owner_user_id' => $this->owner->id,
            'status' => 'active', 'plan_id' => Plan::where('key', 'starter')->value('id'), 'settings' => Tenant::DEFAULT_SETTINGS,
        ]);
        app(CurrentTenant::class)->run($this->org, fn () => TenantMember::create(['user_id' => $this->owner->id, 'role_id' => Role::system('owner')->id, 'status' => 'active']));
        $this->budi = $this->makeUser('budi@majujaya.id', 'Budi');
        app(CurrentTenant::class)->run($this->org, fn () => TenantMember::create(['user_id' => $this->budi->id, 'role_id' => Role::system('member')->id, 'status' => 'active']));

        $this->invoice = app(CurrentTenant::class)->run($this->org, fn () => Invoice::create([
            'tenant_id' => $this->org->id, 'user_id' => $this->owner->id, 'number' => 'INV/2026/11/000001', 'type' => 'subscription', 'status' => 'paid',
            'lines' => [['code' => 'plan', 'label' => 'Starter · 6 bulan', 'qty' => 1, 'amount' => 1000000]],
            'subtotal' => 1000000, 'discount' => 0, 'tax' => 0, 'total' => 1000000, 'plan_id' => Plan::where('key', 'starter')->value('id'),
            'billing_snapshot' => ['name' => 'Owner MJ', 'email' => 'owner@majujaya.id'], 'issued_at' => now(), 'paid_at' => now(),
            'due_at' => now()->addDay(), 'expires_at' => now()->addDay(),
        ]));
    }

    public function test_owner_downloads_invoice_as_pdf(): void
    {
        $this->actingAs($this->owner);
        $res = $this->get($this->host('majujaya', "/api/billing/invoices/{$this->invoice->public_id}/pdf"))->assertOk();

        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('INV-2026-11-000001.pdf', $res->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }

    public function test_pdf_is_stored_once_per_invoice_state(): void
    {
        $this->actingAs($this->owner);
        $url = $this->host('majujaya', "/api/billing/invoices/{$this->invoice->public_id}/pdf");
        $this->get($url)->assertOk();
        $this->get($url)->assertOk();

        $files = Storage::disk('documents')->allFiles('invoices/'.$this->invoice->public_id);
        $this->assertCount(1, $files, 'unduhan kedua memakai berkas yang sama');

        $this->invoice->update(['status' => 'void']);
        $this->get($url)->assertOk();
        $this->assertCount(2, Storage::disk('documents')->allFiles('invoices/'.$this->invoice->public_id), 'isi tagihan berubah → berkas baru');
    }

    public function test_member_without_billing_permission_is_refused(): void
    {
        $this->actingAs($this->budi);
        $this->get($this->host('majujaya', "/api/billing/invoices/{$this->invoice->public_id}/pdf"))->assertStatus(403);
    }
}
