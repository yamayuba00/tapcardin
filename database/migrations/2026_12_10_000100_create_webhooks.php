<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Webhook keluar per workspace (paket dengan fitur `webhooks`): endpoint + log pengiriman (disimpan 30 hari).
 * Add-on minat "Webhook" (segera hadir) dipensiunkan karena fiturnya sudah tersedia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('url', 500);
            $t->string('description', 120)->nullable();
            $t->jsonb('events');
            $t->boolean('is_active')->default(true);
            $t->text('secret'); // terenkripsi (cast `encrypted`)
            $t->unsignedInteger('failure_count')->default(0);
            $t->timestampTz('disabled_at')->nullable();
            $t->string('disabled_reason', 200)->nullable();
            $t->timestampTz('last_success_at')->nullable();
            $t->timestampTz('last_failure_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->index('tenant_id');
        });

        Schema::create('webhook_deliveries', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('endpoint_id')->constrained('webhook_endpoints')->cascadeOnDelete();
            $t->string('event_id', 40); // id event di body; sama untuk kirim ulang manual
            $t->string('type', 40);
            $t->text('body');
            $t->string('status', 12)->default('pending'); // pending | retrying | success | failed
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->unsignedSmallInteger('http_status')->nullable();
            $t->unsignedInteger('duration_ms')->nullable();
            $t->text('response_body')->nullable();
            $t->string('error', 300)->nullable();
            $t->timestampTz('next_retry_at')->nullable();
            $t->timestampTz('delivered_at')->nullable();
            $t->timestampsTz();
            $t->index(['endpoint_id', 'created_at']);
            $t->index(['tenant_id', 'created_at']);
            $t->index('created_at');
        });

        DB::table('addons')->where('key', 'webhooks')->update(['status' => 'retired', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('addons')->where('key', 'webhooks')->update(['status' => 'coming_soon', 'updated_at' => now()]);
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
    }
};
