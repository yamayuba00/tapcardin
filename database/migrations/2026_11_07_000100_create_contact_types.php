<?php

use App\Support\Contacts\ContactCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Jenis kontak & tautan jadi data relasional: `contact_type_groups` 1—n `contact_types`,
 * dan `profile_fields.type` → FK ke `contact_types.key` (menggantikan CHECK 7 nilai).
 * `profile_fields.meta` menyimpan data tambahan (mis. nama bank & pemilik rekening).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_type_groups', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->string('key', 20)->unique();
            $t->string('name', 60);
            $t->smallInteger('sort')->default(0);
            $t->timestampsTz();
        });

        Schema::create('contact_types', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->foreignId('group_id')->constrained('contact_type_groups')->restrictOnDelete();
            $t->string('key', 24)->unique();
            $t->string('label', 60);
            $t->string('input', 10);
            $t->string('action', 10);
            $t->string('vcard', 10)->default('none');
            $t->string('icon', 40);
            $t->string('url_template', 200)->nullable();
            $t->string('placeholder', 120)->nullable();
            $t->smallInteger('sort')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestampsTz();
            $t->index(['group_id', 'sort']);
            $t->index(['is_active', 'sort']);
        });
        DB::statement("ALTER TABLE contact_types ADD CONSTRAINT contact_types_input_chk CHECK (input IN ('phone','email','url','handle','text','bank','wallet','image'))");
        DB::statement("ALTER TABLE contact_types ADD CONSTRAINT contact_types_action_chk CHECK (action IN ('call','link','map','copy','image','none'))");

        $now = now();
        foreach (ContactCatalog::groupRows() as $g) {
            DB::table('contact_type_groups')->insert($g + ['public_id' => (string) Str::ulid(), 'created_at' => $now, 'updated_at' => $now]);
        }
        $groupIds = DB::table('contact_type_groups')->pluck('id', 'key')->all();
        foreach (ContactCatalog::typeRows($groupIds) as $row) {
            DB::table('contact_types')->insert($row + ['public_id' => (string) Str::ulid(), 'created_at' => $now, 'updated_at' => $now]);
        }

        DB::statement('ALTER TABLE profile_fields DROP CONSTRAINT IF EXISTS profile_fields_type_chk');
        Schema::table('profile_fields', function (Blueprint $t) {
            $t->string('type', 24)->change();
            $t->jsonb('meta')->default('{}');
            $t->foreign('type')->references('key')->on('contact_types')->cascadeOnUpdate()->restrictOnDelete();
            $t->index(['profile_id', 'sort']);
            $t->index('type');
        });
    }

    public function down(): void
    {
        Schema::table('profile_fields', function (Blueprint $t) {
            $t->dropForeign(['type']);
            $t->dropIndex(['profile_id', 'sort']);
            $t->dropIndex(['type']);
            $t->dropColumn('meta');
        });
        Schema::dropIfExists('contact_types');
        Schema::dropIfExists('contact_type_groups');
    }
};
