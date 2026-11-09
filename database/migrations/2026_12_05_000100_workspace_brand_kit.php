<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brand kit milik workspace (perusahaan / komunitas / partai / organisasi): dibuat dari logo yang diunggah admin di
 * pengaturan identitas workspace, dipakai SEMUA anggota saat mendesain kartu (kit:logo, {{brand}}). Satu per workspace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_kits', function (Blueprint $t) {
            $t->boolean('is_workspace')->default(false);
            $t->index(['tenant_id', 'is_workspace']);
        });
    }

    public function down(): void
    {
        Schema::table('brand_kits', function (Blueprint $t) {
            $t->dropIndex(['tenant_id', 'is_workspace']);
            $t->dropColumn('is_workspace');
        });
    }
};
