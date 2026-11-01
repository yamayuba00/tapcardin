<?php

namespace Tests\Feature;

use App\Actions\Billing\ApplyPayment;
use App\Models\Design;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PaymentReview;
use App\Models\PlatformStaff;
use App\Models\Refund;
use App\Models\Role;
use App\Models\User;
use App\Services\Payment\PaymentNotification;
use App\Tenancy\TenantScope;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Kontrol keuangan: refund (pembayar asal, dua orang, batas nominal), persetujuan aksi sensitif, antrean tinjauan, tinjauan risiko. */
class RefundAndControlsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function staff(string $role, ?string $email = null): User
    {
        // Pemilik Platform bawaan seeder (SEED_PLATFORM_OWNER_EMAIL) dipakai sebagai "staf lain yang berwenang"
        if ($email === 'owner@tapcard.test') {
            $owner = User::where('email', $email)->firstOrFail();
            PlatformStaff::where('user_id', $owner->id)->update(['status' => 'active', 'last_reverified_at' => now()]);

            return $owner;
        }
        $u = $this->makeUser($email ?? "$role@tapcard.test", ucfirst($role));
        PlatformStaff::create(['user_id' => $u->id, 'role_id' => Role::system($role)->id, 'status' => 'active', 'last_reverified_at' => now()]);

        return $u;
    }

    private function panel(string $method, string $path, array $data = [])
    {
        return $this->json($method, $this->host('panel', "/api$path"), $data);
    }

    private function app_(string $method, string $path, array $data = [])
    {
        return $this->json($method, $this->host('app', "/api$path"), $data);
    }

    /** Pesanan satu kartu (ambil sendiri, Rp65.000 — tanpa tanda risiko) yang sudah dibayar. @return array{order:string, invoice:string, buyer:User} */
    private function paidOrder(string $email = 'rina@example.com'): array
    {
        $buyer = $this->makeUser($email, 'Rina');
        $this->actingAs($buyer);
        $card = $this->app_('POST', '/designs', ['template' => 'onyx'])->assertCreated()->json('data.id');
        Design::withoutGlobalScope(TenantScope::class)->where('public_id', $card)->update(['validated_at' => now()]);
        $o = $this->app_('POST', '/orders', [
            'items' => [['kind' => 'card', 'design' => $card, 'product' => 'pvc_ntag215', 'quantity' => 1]],
            'shipping' => ['method' => 'pickup'],
        ])->assertCreated()->json('data.order');
        $inv = Invoice::withoutGlobalScope(TenantScope::class)->where('order_id', Order::withoutGlobalScopes()->where('public_id', $o['id'])->value('id'))->firstOrFail();
        $this->app_('POST', "/billing/invoices/{$inv->public_id}/simulate", ['result' => 'paid', 'method' => 'QRIS'])->assertJsonPath('data.status', 'paid');

        return ['order' => $o['id'], 'invoice' => $inv->public_id, 'buyer' => $buyer];
    }

    private function refund(string $order, array $over = [])
    {
        return $this->panel('POST', "/orders/$order/refunds", $over + ['reason_code' => 'not_started', 'reason' => 'Pelanggan salah pesan desain']);
    }

    public function test_refund_goes_to_original_payer_with_two_person_rule_and_full_audit_trail(): void
    {
        $p = $this->paidOrder();
        $support = $this->staff('support');
        $finance = $this->staff('finance');

        // Support mengajukan, tetapi tidak berwenang memutuskan / mencatat transfer
        $this->actingAs($support);
        $r = $this->refund($p['order'])->assertCreated()->assertJsonPath('data.status', 'requested')->assertJsonPath('data.amount', 65000)->json('data');
        $this->assertSame('rina@example.com', $r['payer']['email'], 'tujuan dana = pembayar asal (snapshot), bukan input bebas');
        $this->assertArrayNotHasKey('destination', $r);
        $this->panel('POST', "/refunds/{$r['id']}/approve")->assertForbidden();
        $this->panel('POST', "/refunds/{$r['id']}/payout", ['method' => 'QRIS', 'reference' => 'TRX-9001', 'to_original_payer' => true])->assertForbidden();

        // Keuangan menyetujui; transfer belum bisa dicatat sebelum disetujui
        $this->actingAs($finance);
        $this->panel('POST', "/refunds/{$r['id']}/payout", ['method' => 'QRIS', 'reference' => 'TRX-9001', 'to_original_payer' => true])->assertStatus(409)->assertJsonPath('error.code', 'refund_not_approved');
        $this->panel('POST', "/refunds/{$r['id']}/approve", ['note' => 'Sesuai kebijakan'])->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.decided_by', 'finance@tapcard.test');
        $this->assertSame('cancelled', Order::withoutGlobalScopes()->where('public_id', $p['order'])->value('status'), 'refund penuh sebelum produksi membatalkan pesanan');

        // pencatatan transfer wajib: referensi + konfirmasi pembayar asal
        $this->panel('POST', "/refunds/{$r['id']}/payout", ['method' => 'QRIS', 'reference' => 'TRX-9001'])->assertUnprocessable();
        $this->panel('POST', "/refunds/{$r['id']}/payout", ['method' => 'QRIS', 'reference' => 'TRX-9001', 'to_original_payer' => true])->assertOk()->assertJsonPath('data.status', 'paid');
        $this->assertSame(65000, (int) Invoice::withoutGlobalScope(TenantScope::class)->where('public_id', $p['invoice'])->value('refunded_amount'));
        $this->assertDatabaseHas('notifications', ['user_id' => $p['buyer']->id, 'type' => 'order_status']);
        foreach (['refund.requested', 'refund.approved', 'refund.paid'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action, 'scope' => 'platform']);
        }

        // satu pesanan tidak bisa di-refund dua kali
        $this->actingAs($support);
        $this->refund($p['order'], ['reason_code' => 'paid_after_cancel', 'reason' => 'Coba lagi'])->assertStatus(409)->assertJsonPath('error.code', 'refund_exists');
    }

    public function test_requester_cannot_decide_own_refund_when_another_approver_exists(): void
    {
        $p = $this->paidOrder();
        $finance = $this->staff('finance');
        $this->staff('platform_owner', 'owner@tapcard.test'); // staf lain yang berwenang

        $this->actingAs($finance);
        $r = $this->refund($p['order'])->assertCreated()->json('data');
        $this->assertFalse($r['can_decide']);
        $this->panel('POST', "/refunds/{$r['id']}/approve")->assertForbidden()->assertJsonPath('error.code', 'self_approval_forbidden');
        $this->panel('POST', "/refunds/{$r['id']}/reject", ['note' => 'saya sendiri'])->assertForbidden();
        $this->assertDatabaseHas('refunds', ['public_id' => $r['id'], 'status' => 'requested']);

        $this->actingAs(User::where('email', 'owner@tapcard.test')->firstOrFail());
        $this->panel('POST', "/refunds/{$r['id']}/approve")->assertOk()->assertJsonPath('data.self_approved', false);
    }

    public function test_solo_mode_allows_single_owner_but_records_self_approval(): void
    {
        PlatformStaff::where('user_id', User::where('email', 'owner@tapcard.test')->value('id'))->update(['status' => 'revoked']); // hanya satu staf berwenang
        $p = $this->paidOrder();
        $this->actingAs($this->staff('platform_owner'));
        $r = $this->refund($p['order'])->assertCreated()->json('data');
        $this->panel('POST', "/refunds/{$r['id']}/approve")->assertOk()->assertJsonPath('data.self_approved', true);
    }

    public function test_large_or_special_refunds_need_the_owner_and_amounts_are_capped(): void
    {
        config(['tapcard.refund.owner_threshold' => 50000]);
        $p = $this->paidOrder();
        $support = $this->staff('support');
        $finance = $this->staff('finance');
        $owner = $this->staff('platform_owner', 'owner@tapcard.test');

        $this->actingAs($support);
        $this->refund($p['order'], ['amount' => 65001])->assertUnprocessable()->assertJsonPath('error.code', 'refund_amount_invalid');
        $r = $this->refund($p['order'], ['amount' => 60000])->assertCreated()->assertJsonPath('data.requires_owner', true)->json('data');

        $this->actingAs($finance);
        $this->panel('POST', "/refunds/{$r['id']}/approve")->assertForbidden()->assertJsonPath('error.code', 'owner_required');

        $this->actingAs($owner);
        $this->panel('POST', "/refunds/{$r['id']}/approve")->assertOk();
        $this->assertSame('paid', Order::withoutGlobalScopes()->where('public_id', $p['order'])->value('status'), 'refund sebagian tidak membatalkan pesanan');
    }

    public function test_reason_must_match_order_state_and_claims_need_evidence(): void
    {
        $p = $this->paidOrder();
        $this->actingAs($this->staff('support'));
        $this->refund($p['order'], ['reason_code' => 'defect', 'reason' => 'Kartu pecah'])->assertUnprocessable()->assertJsonPath('error.code', 'refund_not_allowed');
        $this->refund($p['order'], ['reason_code' => 'other', 'reason' => 'terlalu pendek'])->assertUnprocessable()->assertJsonPath('error.code', 'reason_too_short');

        // sudah dikirim → klaim cacat butuh bukti
        Order::withoutGlobalScopes()->where('public_id', $p['order'])->update(['status' => 'delivered', 'delivered_at' => now()]);
        $this->refund($p['order'], ['reason_code' => 'defect', 'reason' => 'Kartu pecah di sudut'])->assertUnprocessable()->assertJsonPath('error.code', 'evidence_required');
        $this->refund($p['order'], ['reason_code' => 'defect', 'reason' => 'Kartu pecah di sudut', 'evidence' => ['https://drive.example/foto1']])->assertCreated();
    }

    public function test_claim_window_after_delivery_is_enforced(): void
    {
        $p = $this->paidOrder();
        Order::withoutGlobalScopes()->where('public_id', $p['order'])->update(['status' => 'delivered', 'delivered_at' => now()->subDays(8)]);
        $this->actingAs($this->staff('support'));
        $this->refund($p['order'], ['reason_code' => 'defect', 'reason' => 'Terlambat melapor', 'evidence' => ['foto']])->assertUnprocessable()->assertJsonPath('error.code', 'refund_window');
    }

    public function test_database_blocks_double_refund_and_self_decision(): void
    {
        $p = $this->paidOrder();
        $support = $this->staff('support');
        $this->actingAs($support);
        $r = $this->refund($p['order'])->assertCreated()->json('data');
        $row = Refund::where('public_id', $r['id'])->firstOrFail();

        $this->expectException(QueryException::class); // pengaju = pemutus, bukan mode tunggal → ditolak constraint DB
        $row->update(['status' => 'approved', 'decided_by' => $support->id, 'decided_at' => now(), 'self_approved' => false]);
    }

    public function test_manual_paid_void_and_grant_wait_for_a_different_approver(): void
    {
        $rina = $this->makeUser();
        $this->actingAs($rina);
        $inv = $this->app_('POST', '/billing/checkout', ['plan' => 'personal_pro', 'cycle' => 'monthly'])->json('data.invoice');
        $finance = $this->staff('finance');
        $owner = $this->staff('platform_owner', 'owner@tapcard.test');

        $this->actingAs($this->staff('support', 'support@tapcard.test'));
        $this->panel('POST', "/finance/invoices/{$inv['id']}/mark-paid", ['method' => 'Transfer', 'reference' => 'TRX-1'])->assertForbidden();

        $this->actingAs($finance);
        $this->panel('POST', "/finance/invoices/{$inv['id']}/mark-paid", ['method' => 'Transfer', 'reference' => 'x'])->assertUnprocessable();
        $req = $this->panel('POST', "/finance/invoices/{$inv['id']}/mark-paid", ['method' => 'Transfer BCA', 'reference' => 'TRX-5555'])->assertStatus(202)->json('data');
        $this->panel('POST', "/finance/invoices/{$inv['id']}/mark-paid", ['method' => 'Transfer BCA', 'reference' => 'TRX-6666'])->assertStatus(409)->assertJsonPath('error.code', 'approval_pending');
        $this->panel('POST', "/approvals/{$req['id']}/approve")->assertForbidden()->assertJsonPath('error.code', 'self_approval_forbidden');
        $this->assertSame('issued', Invoice::withoutGlobalScope(TenantScope::class)->where('public_id', $inv['id'])->value('status'));

        $this->actingAs($owner);
        $this->panel('POST', "/approvals/{$req['id']}/approve", ['note' => 'Mutasi cocok'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame('paid', Invoice::withoutGlobalScope(TenantScope::class)->where('public_id', $inv['id'])->value('status'));
        $this->panel('POST', "/approvals/{$req['id']}/approve")->assertStatus(409); // tidak bisa diputuskan dua kali
        foreach (['approval.requested', 'approval.approved', 'invoice.marked_paid'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action]);
        }

        // referensi transfer yang sama tidak bisa dipakai lagi (untuk invoice lain)
        $this->actingAs($this->makeUser('lain@example.com', 'Lain'));
        $inv2 = $this->app_('POST', '/billing/checkout', ['plan' => 'personal_pro', 'cycle' => 'monthly'])->json('data.invoice');
        $this->actingAs($finance);
        $this->panel('POST', "/finance/invoices/{$inv2['id']}/mark-paid", ['method' => 'Transfer', 'reference' => 'trx-5555'])->assertStatus(409)->assertJsonPath('error.code', 'reference_used');
    }

    public function test_rejected_or_expired_approval_changes_nothing(): void
    {
        $rina = $this->makeUser();
        $this->actingAs($rina);
        $inv = $this->app_('POST', '/billing/checkout', ['plan' => 'personal_pro', 'cycle' => 'monthly'])->json('data.invoice');
        $finance = $this->staff('finance');
        $this->staff('platform_owner', 'owner@tapcard.test');

        $this->actingAs($finance);
        $req = $this->panel('POST', "/finance/invoices/{$inv['id']}/void", ['reason' => 'Salah tagih', 'reference' => 'TKT-1'])->assertStatus(202)->json('data');
        $this->actingAs(User::where('email', 'owner@tapcard.test')->firstOrFail());
        $this->panel('POST', "/approvals/{$req['id']}/reject", ['note' => 'Tidak valid, tagihan benar'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame('issued', Invoice::withoutGlobalScope(TenantScope::class)->where('public_id', $inv['id'])->value('status'));

        $this->actingAs($finance);
        $req2 = $this->panel('POST', "/finance/invoices/{$inv['id']}/void", ['reason' => 'Coba lagi', 'reference' => 'TKT-2'])->assertStatus(202)->json('data');
        $this->travel(4)->days();
        PlatformStaff::query()->update(['last_reverified_at' => now()]); // sesi staf diverifikasi ulang setelah 12 jam
        $this->actingAs(User::where('email', 'owner@tapcard.test')->firstOrFail());
        $this->panel('POST', "/approvals/{$req2['id']}/approve")->assertStatus(409)->assertJsonPath('error.code', 'approval_expired');
        $this->assertSame('issued', Invoice::withoutGlobalScope(TenantScope::class)->where('public_id', $inv['id'])->value('status'));
    }

    public function test_amount_mismatch_unknown_reference_and_payment_after_cancel_land_in_review_queue(): void
    {
        $rina = $this->makeUser();
        $this->actingAs($rina);
        $inv = $this->app_('POST', '/billing/checkout', ['plan' => 'personal_pro', 'cycle' => 'monthly'])->json('data.invoice');
        $apply = app(ApplyPayment::class);
        $pay = fn (string $ref, int $amount) => $apply->handle(new PaymentNotification('qris', 'evt-'.Str::ulid(), $ref, PaymentNotification::PAID, $amount, 'QRIS', true, []));

        $this->assertSame('rejected', $pay($inv['id'], 1000));
        $this->assertSame('rejected', $pay('INV-TIDAK-ADA', 49000));
        $this->assertSame('issued', Invoice::withoutGlobalScope(TenantScope::class)->where('public_id', $inv['id'])->value('status'), 'nominal salah tidak mengaktifkan paket');
        $this->assertSame(['amount_mismatch', 'unknown_invoice'], PaymentReview::orderBy('id')->pluck('kind')->all());

        // pesanan dibatalkan lalu invoice-nya dibayar
        $p = $this->paidOrder('lain@example.com');
        $this->actingAs($this->staff('platform_owner'));
        Order::withoutGlobalScopes()->where('public_id', $p['order'])->update(['status' => 'cancelled']);
        Invoice::withoutGlobalScope(TenantScope::class)->where('public_id', $p['invoice'])->update(['status' => 'void']);
        $this->assertSame('paid', $pay($p['invoice'], 65000));
        $this->assertDatabaseHas('payment_reviews', ['kind' => 'paid_after_cancel', 'status' => 'open']);

        $list = $this->panel('GET', '/payment-reviews?status=open')->assertOk()->assertJsonPath('meta.total', 3)->json('data');
        $this->panel('GET', '/dashboard')->assertJsonPath('data.attention.payment_reviews', 3);
        $this->panel('POST', "/payment-reviews/{$list[0]['id']}/resolve", ['outcome' => 'resolved', 'note' => 'x'])->assertUnprocessable();
        $this->panel('POST', "/payment-reviews/{$list[0]['id']}/resolve", ['outcome' => 'resolved', 'note' => 'Dikembalikan, ref TRX-77'])->assertOk()->assertJsonPath('data.status', 'resolved');
        $this->panel('POST', "/payment-reviews/{$list[0]['id']}/resolve", ['outcome' => 'dismissed', 'note' => 'Sudah ditutup'])->assertStatus(409);

        $this->actingAs($this->staff('support', 'support@tapcard.test'));
        $this->panel('GET', '/payment-reviews')->assertForbidden();
    }

    public function test_risky_order_must_be_reviewed_and_rejection_cancels_and_requests_refund(): void
    {
        config(['tapcard.risk.blocked_terms' => ['rolex']]);
        $buyer = $this->makeUser('rina@example.com', 'Rina');
        $this->actingAs($buyer);
        $card = $this->app_('POST', '/designs', ['template' => 'onyx'])->assertCreated()->json('data.id');
        Design::withoutGlobalScope(TenantScope::class)->where('public_id', $card)->update(['validated_at' => now()]);
        $d = Design::withoutGlobalScope(TenantScope::class)->where('public_id', $card)->firstOrFail();
        $front = $d->front_json;
        $front['layers'][] = ['id' => 'x1', 'type' => 'text', 'text' => 'Official ROLEX Dealer', 'x' => 5, 'y' => 5, 'size' => 8];
        $d->update(['front_json' => $front]);
        $o = $this->app_('POST', '/orders', [
            'items' => [['kind' => 'card', 'design' => $card, 'product' => 'pvc_ntag215', 'quantity' => 1]], 'shipping' => ['method' => 'pickup'],
        ])->assertCreated()->json('data.order');
        $inv = Invoice::withoutGlobalScope(TenantScope::class)->where('type', 'order')->firstOrFail();
        $this->app_('POST', "/billing/invoices/{$inv->public_id}/simulate", ['result' => 'paid', 'method' => 'QRIS']);

        $this->actingAs($this->staff('support'));
        $this->panel('POST', "/orders/{$o['id']}/review", ['decision' => 'approve', 'note' => 'coba'])->assertForbidden();

        $reviewer = $this->staff('design_reviewer');
        $this->actingAs($reviewer);
        $detail = $this->panel('GET', "/orders/{$o['id']}")->assertOk()->assertJsonPath('data.review.status', 'pending')->json('data.review');
        $this->assertContains('blocked_term', array_column($detail['flags'], 'code'));
        $this->assertContains('Official ROLEX Dealer', $detail['texts'], 'reviewer melihat semua teks yang akan tercetak');
        $this->panel('POST', "/orders/{$o['id']}/review", ['decision' => 'reject', 'note' => 'Memuat merek dagang pihak lain'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseHas('refunds', ['reason_code' => 'not_started', 'status' => 'requested', 'amount' => 65000]);

        $this->actingAs($this->staff('finance'));
        $refundId = Refund::value('public_id');
        $this->panel('POST', "/refunds/$refundId/approve")->assertOk(); // diputuskan keuangan (≠ peninjau yang otomatis mengajukan)
    }
}
