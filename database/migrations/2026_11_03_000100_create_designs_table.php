<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** BSD §4.4 `designs` + orientasi & lanyard (permintaan pemilik produk: kartu bisa dipakai dengan lanyard). */
    public function up(): void
    {
        Schema::create('designs', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->foreignId('tenant_id')->nullable()->constrained(); // null = template global (v1: dikelola panel pemilik)
            $t->foreignId('user_id')->nullable()->constrained();
            $t->string('name', 120);
            $t->string('template_key', 32)->nullable();
            $t->string('orientation', 10)->default('landscape');
            $t->boolean('lanyard')->default(false);
            $t->boolean('is_template')->default(false);
            $t->boolean('is_published')->default(false);
            $t->smallInteger('sort')->default(0);
            $t->string('material', 16)->default('pvc_white');
            $t->jsonb('front_json');
            $t->jsonb('back_json');
            $t->jsonb('variables')->default('{}');
            $t->jsonb('locked_layer_ids')->default('[]');
            $t->string('preview_front_path')->nullable();
            $t->string('preview_back_path')->nullable();
            $t->string('print_pdf_path')->nullable();
            $t->integer('version')->default(1);
            $t->timestampTz('validated_at')->nullable();
            $t->timestampsTz();
            $t->softDeletesTz();
            $t->index(['tenant_id', 'user_id']);
        });
        DB::statement("ALTER TABLE designs ADD CONSTRAINT designs_orientation_chk CHECK (orientation IN ('landscape','portrait'))");
        DB::statement("ALTER TABLE designs ADD CONSTRAINT designs_material_chk CHECK (material IN ('pvc_white','pvc_black','wood','metal'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('designs');
    }
};
