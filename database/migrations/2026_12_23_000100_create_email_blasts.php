<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Email blast promo dari panel (terpisah dari pengumuman in-app):
 *  - email_blasts: isi (markdown), audiens, status kirim & hitungan.
 *  - email_blast_recipients: satu baris per alamat (unik per blast) → kirim ulang tidak menggandakan email.
 *  - email_unsubscribes: alamat yang berhenti berlangganan (dikecualikan saat kirim).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_blasts', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->string('subject', 150);
            $t->string('preheader', 200)->nullable();
            $t->text('body');
            $t->string('audience', 12)->default('all'); // all | personal | company | plan | test
            $t->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $t->string('status', 12)->default('draft'); // draft | scheduled | sending | sent | cancelled
            $t->timestampTz('scheduled_at')->nullable();
            $t->timestampTz('started_at')->nullable();
            $t->timestampTz('finished_at')->nullable();
            $t->unsignedInteger('total_count')->default(0);
            $t->unsignedInteger('sent_count')->default(0);
            $t->unsignedInteger('failed_count')->default(0);
            $t->unsignedInteger('skipped_count')->default(0);
            $t->foreignId('created_by')->nullable()->constrained('staff_accounts')->nullOnDelete();
            $t->timestampsTz();
            $t->index(['status', 'scheduled_at']);
        });
        DB::statement("ALTER TABLE email_blasts ADD CONSTRAINT email_blasts_audience_chk CHECK (audience IN ('all','personal','company','plan','test'))");
        DB::statement("ALTER TABLE email_blasts ADD CONSTRAINT email_blasts_status_chk CHECK (status IN ('draft','scheduled','sending','sent','cancelled'))");

        Schema::create('email_blast_recipients', function (Blueprint $t) {
            $t->id();
            $t->foreignId('blast_id')->constrained('email_blasts')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('email', 190);
            $t->string('status', 8)->default('pending'); // pending | sent | failed | skipped
            $t->string('error', 255)->nullable();
            $t->timestampTz('sent_at')->nullable();
            $t->unique(['blast_id', 'email']);
            $t->index(['blast_id', 'status']);
        });
        DB::statement("ALTER TABLE email_blast_recipients ADD CONSTRAINT email_blast_recipients_status_chk CHECK (status IN ('pending','sent','failed','skipped'))");

        Schema::create('email_unsubscribes', function (Blueprint $t) {
            $t->id();
            $t->string('email', 190)->unique(); // disimpan huruf kecil
            $t->string('reason', 120)->nullable();
            $t->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_unsubscribes');
        Schema::dropIfExists('email_blast_recipients');
        Schema::dropIfExists('email_blasts');
    }
};
