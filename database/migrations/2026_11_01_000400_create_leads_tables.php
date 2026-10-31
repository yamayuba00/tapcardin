<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_stages', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->foreignId('tenant_id')->constrained();
            $t->string('name', 40);
            $t->string('kind', 6)->default('open');
            $t->smallInteger('sort')->default(0);
            $t->string('color', 16);
            $t->boolean('is_default')->default(false);
            $t->unique(['tenant_id', 'name']);
        });
        DB::statement("ALTER TABLE lead_stages ADD CONSTRAINT lead_stages_kind_chk CHECK (kind IN ('open','won','lost'))");
        DB::statement('CREATE UNIQUE INDEX lead_stages_default_uq ON lead_stages (tenant_id) WHERE is_default');

        Schema::create('connections', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->foreignId('tenant_id')->constrained();
            $t->string('source', 24);
            $t->foreignId('captured_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('assignee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('card_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedBigInteger('tap_id')->nullable();
            $t->foreignId('stage_id')->constrained('lead_stages');
            $t->string('name', 120);
            $t->string('email', 190)->nullable();
            $t->string('email_norm', 190)->nullable();
            $t->string('phone', 32)->nullable();
            $t->string('phone_e164', 16)->nullable();
            $t->string('company', 120)->nullable();
            $t->string('job_title', 120)->nullable();
            $t->string('external_url', 500)->nullable();
            $t->foreignId('counterpart_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('counterpart_card_id')->nullable()->constrained('cards')->nullOnDelete();
            $t->text('note')->nullable();
            $t->jsonb('custom')->default('{}');
            $t->bigInteger('value_amount')->nullable();
            $t->date('expected_close_at')->nullable();
            $t->string('outcome', 4)->nullable();
            $t->string('lost_reason', 80)->nullable();
            $t->timestampTz('closed_at')->nullable();
            $t->timestampTz('first_activity_at')->nullable();
            $t->timestampTz('last_activity_at')->nullable()->index();
            $t->timestampTz('next_followup_at')->nullable();
            $t->foreignId('merged_into_id')->nullable()->constrained('connections');
            $t->timestampTz('consent_at')->nullable();
            $t->string('consent_text_version', 10)->nullable();
            $t->uuid('client_uuid')->nullable();
            $t->timestampTz('scanned_at')->nullable();
            $t->timestampsTz();
            $t->softDeletesTz();
            $t->unique(['tenant_id', 'client_uuid']);
            $t->index(['tenant_id', 'assignee_user_id', 'stage_id']);
            $t->index(['tenant_id', 'team_id', 'created_at']);
            $t->index(['tenant_id', 'next_followup_at']);
            $t->index(['tenant_id', 'phone_e164']);
            $t->index(['tenant_id', 'email_norm']);
        });
        DB::statement("ALTER TABLE connections ADD COLUMN tags text[] NOT NULL DEFAULT '{}'");
        DB::statement('CREATE INDEX connections_tags_gin ON connections USING GIN (tags)');
        DB::statement("ALTER TABLE connections ADD CONSTRAINT connections_source_chk CHECK
            (source IN ('exchange_form','scan_tapcard','scan_external','manual','import','event_checkin','saved_from_profile'))");

        Schema::create('lead_activities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('connection_id')->index()->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->string('type', 16);
            $t->text('body')->nullable();
            $t->jsonb('meta')->default('{}');
            $t->timestampTz('occurred_at');
            $t->timestampTz('created_at')->useCurrent();
        });

        Schema::create('lead_reminders', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('connection_id')->index()->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->index()->constrained();
            $t->timestampTz('due_at')->index();
            $t->string('note', 200)->nullable();
            $t->boolean('is_auto')->default(false);
            $t->timestampTz('done_at')->nullable();
            $t->timestampTz('notified_at')->nullable();
            $t->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_reminders');
        Schema::dropIfExists('lead_activities');
        Schema::dropIfExists('connections');
        Schema::dropIfExists('lead_stages');
    }
};
