<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LD-10 — impor CSV koneksi (unggah → pratinjau & pemetaan kolom → job per 500 baris → ringkasan),
 * dan `users.preferences` untuk pengaturan per pengguna (mis. tata letak dashboard lead).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_imports', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->string('filename', 160);
            $t->string('path');
            $t->string('status', 12)->default('uploaded'); // uploaded | queued | processing | done | failed
            $t->char('delimiter', 1)->default(',');
            $t->jsonb('headers')->default('[]');
            $t->jsonb('mapping')->default('{}');
            $t->unsignedInteger('total_rows')->default(0);
            $t->unsignedInteger('created_count')->default(0);
            $t->unsignedInteger('merged_count')->default(0);
            $t->unsignedInteger('failed_count')->default(0);
            $t->jsonb('errors')->default('[]'); // maks 50 contoh {row, reason}
            $t->timestampTz('finished_at')->nullable();
            $t->timestampsTz();
            $t->index(['tenant_id', 'created_at']);
        });

        Schema::table('users', fn (Blueprint $t) => $t->jsonb('preferences')->default('{}'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('preferences'));
        Schema::dropIfExists('lead_imports');
    }
};
