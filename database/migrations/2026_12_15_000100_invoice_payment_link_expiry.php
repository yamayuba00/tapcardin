<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Link bayar gateway punya umur sendiri (mis. 1 jam). Disimpan agar link basi tidak pernah diberikan lagi:
 * lewat batas → link baru dibuat (order_id ber-akhiran -r{n} karena gateway menolak order_id ganda).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->timestampTz('payment_url_expires_at')->nullable()->after('payment_url');
            $t->unsignedSmallInteger('payment_attempts')->default(0)->after('payment_url_expires_at');
        });
        // tinjauan baru: dibayar dua kali (link lain) & kabar lunas tanpa nominal
        DB::statement('ALTER TABLE payment_reviews DROP CONSTRAINT IF EXISTS payment_reviews_kind_chk');
        DB::statement("ALTER TABLE payment_reviews ADD CONSTRAINT payment_reviews_kind_chk CHECK (kind IN ('amount_mismatch','unknown_invoice','paid_after_cancel','duplicate_payment','amount_missing'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payment_reviews DROP CONSTRAINT IF EXISTS payment_reviews_kind_chk');
        DB::statement("ALTER TABLE payment_reviews ADD CONSTRAINT payment_reviews_kind_chk CHECK (kind IN ('amount_mismatch','unknown_invoice','paid_after_cancel')) NOT VALID");
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropColumn(['payment_url_expires_at', 'payment_attempts']);
        });
    }
};
