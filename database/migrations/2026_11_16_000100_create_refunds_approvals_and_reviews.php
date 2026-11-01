<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kontrol keuangan:
 *  - refunds: pengaju ≠ penyetuju (CHECK di DB), dana hanya ke pembayar asal (snapshot pembayar, tanpa tujuan bebas),
 *    satu refund aktif per pesanan (unique index parsial), nominal tidak melebihi invoice (divalidasi + dikunci di aksi);
 *  - approval_requests: aksi keuangan sensitif (tandai lunas manual, batalkan invoice, beri paket) → disetujui/ditolak orang lain;
 *  - payment_reviews: antrean pembayaran yang masuk tapi tidak bisa diproses otomatis (nominal beda, referensi tak dikenal, dst.);
 *  - orders.*: skor & tanda risiko + status tinjauan sebelum produksi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->foreignId('order_id')->constrained('orders');
            $t->foreignId('invoice_id')->constrained('invoices');
            $t->foreignId('tenant_id')->constrained('tenants');
            $t->bigInteger('amount');
            $t->string('reason_code', 32);
            $t->string('reason', 500);
            $t->jsonb('evidence')->default('[]');
            $t->string('status', 12)->default('requested');
            $t->boolean('requires_owner')->default(false);
            $t->boolean('self_approved')->default(false); // true hanya bila tidak ada staf lain yang berwenang (mode tunggal)
            $t->foreignId('requested_by')->constrained('users');
            $t->foreignId('decided_by')->nullable()->constrained('users');
            $t->timestampTz('decided_at')->nullable();
            $t->string('decision_note', 300)->nullable();
            $t->jsonb('payer'); // snapshot pembayar asal: gateway, metode, referensi, nama, email
            $t->string('payout_method', 60)->nullable();
            $t->string('payout_reference', 80)->nullable();
            $t->foreignId('paid_by')->nullable()->constrained('users');
            $t->timestampTz('paid_at')->nullable();
            $t->timestampsTz();
            $t->index(['status', 'created_at']);
            $t->index('order_id');
        });
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_status_chk CHECK (status IN ('requested','approved','rejected','paid','cancelled'))");
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount_chk CHECK (amount > 0)');
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_two_person_chk CHECK (decided_by IS NULL OR self_approved OR decided_by <> requested_by)');
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_payer_chk CHECK (paid_by IS NULL OR self_approved OR paid_by <> requested_by)');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_paid_chk CHECK (status <> 'paid' OR (payout_reference IS NOT NULL AND paid_at IS NOT NULL AND paid_by IS NOT NULL))");
        // satu pesanan tidak bisa direfund dua kali: hanya satu refund aktif (diajukan / disetujui / dibayar)
        DB::statement("CREATE UNIQUE INDEX refunds_active_order_uq ON refunds (order_id) WHERE status IN ('requested','approved','paid')");
        // satu bukti transfer tidak bisa dipakai untuk dua refund
        DB::statement('CREATE UNIQUE INDEX refunds_payout_ref_uq ON refunds (lower(payout_reference)) WHERE payout_reference IS NOT NULL');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_reason_chk CHECK (reason_code IN ('not_started','paid_after_cancel','duplicate_payment','defect','wrong_print','not_delivered','other'))");

        Schema::create('approval_requests', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->string('type', 24);
            $t->string('summary', 240);
            $t->jsonb('payload');
            $t->bigInteger('amount')->nullable();
            $t->string('reference', 80);
            $t->string('reason', 300)->nullable();
            $t->string('status', 10)->default('pending');
            $t->foreignId('requested_by')->constrained('users');
            $t->foreignId('decided_by')->nullable()->constrained('users');
            $t->timestampTz('decided_at')->nullable();
            $t->string('decision_note', 300)->nullable();
            $t->boolean('self_approved')->default(false);
            $t->timestampTz('executed_at')->nullable();
            $t->jsonb('result')->nullable();
            $t->timestampTz('expires_at');
            $t->timestampsTz();
            $t->index(['status', 'created_at']);
            $t->index('requested_by');
        });
        DB::statement("ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_type_chk CHECK (type IN ('invoice.mark_paid','invoice.void','plan.grant'))");
        DB::statement("ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_status_chk CHECK (status IN ('pending','approved','rejected','cancelled','expired','failed'))");
        DB::statement('ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_two_person_chk CHECK (decided_by IS NULL OR self_approved OR decided_by <> requested_by)');

        Schema::create('payment_reviews', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->foreignId('payment_event_id')->unique()->constrained('payment_events')->cascadeOnDelete();
            $t->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $t->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $t->string('kind', 24);
            $t->bigInteger('expected_amount')->nullable();
            $t->bigInteger('received_amount')->nullable();
            $t->string('message', 240);
            $t->string('status', 10)->default('open');
            $t->string('resolution_note', 300)->nullable();
            $t->foreignId('resolved_by')->nullable()->constrained('users');
            $t->timestampTz('resolved_at')->nullable();
            $t->timestampsTz();
            $t->index(['status', 'created_at']);
        });
        DB::statement("ALTER TABLE payment_reviews ADD CONSTRAINT payment_reviews_status_chk CHECK (status IN ('open','resolved','dismissed'))");
        DB::statement("ALTER TABLE payment_reviews ADD CONSTRAINT payment_reviews_kind_chk CHECK (kind IN ('amount_mismatch','unknown_invoice','paid_after_cancel'))");

        Schema::table('orders', function (Blueprint $t) {
            $t->smallInteger('risk_score')->default(0);
            $t->jsonb('risk_flags')->default('[]');
            $t->string('review_status', 10)->default('none');
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('reviewed_at')->nullable();
            $t->string('review_note', 300)->nullable();
            $t->index('review_status');
        });
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_review_status_chk CHECK (review_status IN ('none','pending','approved','rejected'))");

        Schema::table('invoices', function (Blueprint $t) {
            $t->bigInteger('refunded_amount')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('refunded_amount'));
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_review_status_chk');
        Schema::table('orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('reviewed_by');
            $t->dropColumn(['risk_score', 'risk_flags', 'review_status', 'reviewed_at', 'review_note']);
        });
        Schema::dropIfExists('payment_reviews');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('refunds');
    }
};
