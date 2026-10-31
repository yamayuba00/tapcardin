<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('user_id')->index()->constrained();
            $t->string('label', 40);
            $t->string('display_name', 120);
            $t->string('title', 120)->nullable();
            $t->string('company', 120)->nullable();
            $t->string('bio', 500)->nullable();
            $t->string('avatar_path')->nullable();
            $t->string('vcard_photo_path')->nullable();
            $t->jsonb('theme')->default('{}');
            $t->string('locale', 5)->default('id');
            $t->boolean('is_indexable')->default(false);
            $t->boolean('show_exchange_form')->default(true);
            $t->timestampTz('archived_at')->nullable();
            $t->timestampsTz();
            $t->softDeletesTz();
            $t->index(['tenant_id', 'user_id']);
        });

        Schema::create('profile_fields', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('profile_id')->index()->constrained()->cascadeOnDelete();
            $t->string('type', 16);
            $t->string('label', 60);
            $t->string('value', 500);
            $t->boolean('is_primary')->default(false);
            $t->boolean('is_visible')->default(true);
            $t->boolean('in_vcard')->default(true);
            $t->boolean('is_locked')->default(false);
            $t->smallInteger('sort')->default(0);
            $t->string('file_path')->nullable();
            $t->integer('click_count')->default(0);
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE profile_fields ADD CONSTRAINT profile_fields_type_chk CHECK
            (type IN ('phone','whatsapp','email','url','address','social','file'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_fields');
        Schema::dropIfExists('profiles');
    }
};
