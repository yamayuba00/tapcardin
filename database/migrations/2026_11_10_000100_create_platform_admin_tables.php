<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Panel pemilik — data yang dikelola staf:
 *  - tenant_domains: domain kustom workspace (verifikasi TXT `_tapcard.{domain}`), alias dari {slug}.domain.com;
 *  - vouchers + voucher_redemptions: diskon checkout paket (persen / nominal, kuota, masa berlaku, paket tertentu);
 *  - announcements: pengumuman ke pengguna (dikirim sebagai notifikasi `announcement`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_domains', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('domain', 190)->unique();
            $t->string('status', 10)->default('pending'); // pending | active | disabled
            $t->char('verify_token', 32);
            $t->timestampTz('verified_at')->nullable();
            $t->timestampTz('last_checked_at')->nullable();
            $t->string('last_error', 200)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->index(['tenant_id', 'status']);
        });
        DB::statement("ALTER TABLE tenant_domains ADD CONSTRAINT tenant_domains_status_chk CHECK (status IN ('pending','active','disabled'))");
        DB::statement('CREATE INDEX tenant_domains_active_idx ON tenant_domains (domain) WHERE status = \'active\'');

        Schema::create('vouchers', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->string('code', 32)->unique();
            $t->string('description', 160)->nullable();
            $t->string('kind', 8); // percent | amount
            $t->unsignedInteger('value');
            $t->unsignedInteger('max_redemptions')->nullable();
            $t->unsignedInteger('redeemed_count')->default(0);
            $t->jsonb('plan_keys')->default('[]'); // kosong = semua paket berbayar
            $t->timestampTz('starts_at')->nullable();
            $t->timestampTz('ends_at')->nullable();
            $t->boolean('is_active')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->index(['is_active', 'ends_at']);
        });
        DB::statement("ALTER TABLE vouchers ADD CONSTRAINT vouchers_kind_chk CHECK (kind IN ('percent','amount'))");
        DB::statement("ALTER TABLE vouchers ADD CONSTRAINT vouchers_percent_chk CHECK (kind <> 'percent' OR value BETWEEN 1 AND 100)");

        Schema::table('invoices', function (Blueprint $t) {
            $t->foreignId('voucher_id')->nullable()->after('plan_id')->constrained('vouchers')->nullOnDelete();
            $t->index('voucher_id');
        });

        Schema::create('voucher_redemptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('voucher_id')->constrained()->cascadeOnDelete();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('invoice_id')->unique()->constrained()->cascadeOnDelete();
            $t->bigInteger('amount');
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['voucher_id', 'tenant_id']); // satu workspace satu kali per voucher
        });

        Schema::create('announcements', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->string('title', 120);
            $t->string('body', 500);
            $t->string('audience', 12); // all | personal | company | plan
            $t->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $t->unsignedInteger('recipients')->default(0);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('sent_at')->nullable();
            $t->timestampsTz();
            $t->index('sent_at');
        });
        DB::statement("ALTER TABLE announcements ADD CONSTRAINT announcements_audience_chk CHECK (audience IN ('all','personal','company','plan'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('voucher_redemptions');
        Schema::table('invoices', fn (Blueprint $t) => $t->dropConstrainedForeignId('voucher_id'));
        Schema::dropIfExists('vouchers');
        Schema::dropIfExists('tenant_domains');
    }
};
