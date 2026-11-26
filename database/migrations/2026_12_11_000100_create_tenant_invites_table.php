<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Undangan karyawan lewat email (workspace perusahaan). Token disimpan sebagai SHA-256, tidak pernah plaintext. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_invites', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('email', 190);
            $t->foreignId('role_id')->constrained()->restrictOnDelete();
            $t->char('token_hash', 64)->unique();
            $t->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('expires_at');
            $t->timestampTz('accepted_at')->nullable();
            $t->timestampTz('revoked_at')->nullable();
            $t->timestampsTz();
            $t->index(['tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_invites');
    }
};
