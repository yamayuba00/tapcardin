<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1. Blok "Formulir" di bio link: pertanyaan dibuat pemilik (tanpa unggah file). Jawaban disimpan di
 *    form_submissions (salinan label + nilai saat dikirim) dan menjadi koneksi bersumber `bio_form`.
 * 2. Kartu yang di-assign ke event: selama event berlangsung, tap & koneksi dari kartu itu otomatis tercatat ke event.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE bio_blocks DROP CONSTRAINT bio_blocks_type_chk');
        DB::statement("ALTER TABLE bio_blocks ADD CONSTRAINT bio_blocks_type_chk CHECK
            (type IN ('save_contact','contacts','button','text','video','divider','form'))");

        DB::statement('ALTER TABLE connections DROP CONSTRAINT connections_source_chk');
        DB::statement("ALTER TABLE connections ADD CONSTRAINT connections_source_chk CHECK
            (source IN ('exchange_form','scan_tapcard','scan_external','manual','import','event_checkin','saved_from_profile','bio_form'))");

        Schema::create('form_submissions', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('bio_block_id')->nullable()->index()->constrained()->nullOnDelete(); // blok boleh dihapus; jawaban tetap
            $t->foreignId('connection_id')->nullable()->index()->constrained()->nullOnDelete();
            $t->foreignId('card_id')->nullable()->index()->constrained()->nullOnDelete();
            $t->foreignId('event_id')->nullable()->index()->constrained()->nullOnDelete();
            $t->string('form_title', 80);
            $t->jsonb('answers')->default('[]'); // [{label, type, value}] — salinan saat dikirim
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['tenant_id', 'created_at']);
        });

        Schema::create('event_cards', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('event_id')->constrained()->cascadeOnDelete();
            $t->foreignId('card_id')->index()->constrained()->cascadeOnDelete();
            $t->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['event_id', 'card_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_cards');
        Schema::dropIfExists('form_submissions');
        DB::table('bio_blocks')->where('type', 'form')->delete();
        DB::statement('ALTER TABLE bio_blocks DROP CONSTRAINT bio_blocks_type_chk');
        DB::statement("ALTER TABLE bio_blocks ADD CONSTRAINT bio_blocks_type_chk CHECK
            (type IN ('save_contact','contacts','button','text','video','divider'))");
        DB::table('connections')->where('source', 'bio_form')->update(['source' => 'exchange_form']);
        DB::statement('ALTER TABLE connections DROP CONSTRAINT connections_source_chk');
        DB::statement("ALTER TABLE connections ADD CONSTRAINT connections_source_chk CHECK
            (source IN ('exchange_form','scan_tapcard','scan_external','manual','import','event_checkin','saved_from_profile'))");
    }
};
