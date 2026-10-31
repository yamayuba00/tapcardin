<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batches', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->string('code', 20)->unique();
            $t->string('status', 20)->default('draft');
            $t->string('chip_type', 16);
            $t->integer('quantity');
            $t->string('vendor', 80)->nullable();
            $t->foreignId('tenant_id')->nullable()->constrained(); // kartu batch langsung milik workspace ini
            $t->foreignId('holder_user_id')->nullable()->constrained('users'); // calon pemegang (pesanan personal)
            $t->text('master_key_enc')->nullable();
            $t->string('print_bundle_path')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestampsTz();
            $t->timestampTz('completed_at')->nullable();
        });
        DB::statement("ALTER TABLE batches ADD CONSTRAINT batches_status_chk CHECK
            (status IN ('draft','tokens_generated','sent_to_print','printed','encoding','qc','completed','cancelled'))");

        Schema::create('cards', function (Blueprint $t) {
            $t->id();
            $t->char('card_token', 10)->unique();
            $t->char('short_code', 8)->unique();
            $t->string('status', 16)->default('manufactured')->index();
            $t->string('chip_type', 16);
            $t->text('chip_uid_enc')->nullable();
            $t->char('chip_uid_hash', 64)->nullable()->unique();
            $t->integer('sdm_counter')->default(0);
            $t->string('activation_code_hash')->nullable();
            $t->smallInteger('activation_attempts')->default(0);
            $t->timestampTz('activation_locked_until')->nullable();
            $t->foreignId('tenant_id')->nullable()->constrained();
            $t->foreignId('holder_user_id')->nullable()->index()->constrained('users');
            $t->foreignId('assigned_user_id')->nullable()->constrained('users');
            $t->foreignId('profile_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('batch_id')->index()->constrained();
            $t->integer('sequence');
            $t->unsignedBigInteger('order_item_id')->nullable();
            $t->foreignId('replaces_card_id')->nullable()->constrained('cards');
            $t->string('label', 40)->nullable();
            foreach (['encoded_at', 'qc_passed_at', 'shipped_at', 'activated_at', 'lost_at', 'revoked_at', 'last_tap_at'] as $col) {
                $t->timestampTz($col)->nullable();
            }
            $t->timestampsTz();
            $t->index(['tenant_id', 'status']);
            $t->index(['tenant_id', 'holder_user_id']);
            $t->unique(['batch_id', 'sequence']);
        });
        DB::statement("ALTER TABLE cards ADD CONSTRAINT cards_status_chk CHECK
            (status IN ('manufactured','unclaimed','active','paused','lost','revoked'))");
        DB::statement("ALTER TABLE cards ADD CONSTRAINT cards_chip_chk CHECK
            (chip_type IN ('ntag213','ntag215','ntag216','ntag424'))");
        DB::statement("ALTER TABLE cards ADD CONSTRAINT cards_active_chk CHECK
            (status <> 'active' OR (tenant_id IS NOT NULL AND holder_user_id IS NOT NULL AND profile_id IS NOT NULL))");

        Schema::create('card_status_history', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->nullable()->constrained();
            $t->foreignId('card_id')->index()->constrained()->cascadeOnDelete();
            $t->string('from_status', 16);
            $t->string('to_status', 16);
            $t->foreignId('actor_user_id')->nullable()->constrained('users');
            $t->string('reason', 200)->nullable();
            $t->timestampTz('created_at')->useCurrent();
        });

        Schema::create('dynamic_qr_tokens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('card_id')->constrained()->cascadeOnDelete();
            $t->char('token_hash', 64)->nullable()->unique();
            $t->unsignedBigInteger('device_id')->nullable();
            $t->char('nonce', 16)->nullable();
            $t->timestampTz('expires_at');
            $t->timestampTz('used_at')->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['device_id', 'nonce']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dynamic_qr_tokens');
        Schema::dropIfExists('card_status_history');
        Schema::dropIfExists('cards');
        Schema::dropIfExists('batches');
    }
};
