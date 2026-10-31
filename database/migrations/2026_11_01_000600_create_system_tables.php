<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->string('type', 40);
            $t->jsonb('data')->default('{}');
            $t->timestampTz('read_at')->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['user_id', 'id']);
        });

        Schema::create('impersonations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('staff_user_id')->constrained('users');
            $t->foreignId('target_user_id')->constrained('users');
            $t->foreignId('tenant_id')->nullable()->constrained();
            $t->string('reason', 300);
            $t->string('ticket_ref', 60)->nullable();
            $t->timestampTz('started_at');
            $t->timestampTz('expires_at');
            $t->timestampTz('ended_at')->nullable();
            $t->char('ip_hash', 64);
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->string('scope', 10);
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->unsignedBigInteger('actor_user_id')->nullable();
            $t->foreignId('impersonation_id')->nullable()->constrained();
            $t->string('action', 60);
            $t->string('subject_type', 40);
            $t->unsignedBigInteger('subject_id');
            $t->jsonb('before')->nullable();
            $t->jsonb('after')->nullable();
            $t->char('ip_hash', 64)->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['tenant_id', 'created_at']);
            $t->index(['scope', 'created_at']);
            $t->index('actor_user_id');
        });

        Schema::create('profile_reports', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->foreignId('card_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('profile_id')->nullable()->constrained()->nullOnDelete();
            $t->string('reason', 30);
            $t->text('details')->nullable();
            $t->string('reporter_email', 190)->nullable();
            $t->char('ip_hash', 64);
            $t->string('status', 10)->default('open');
            $t->foreignId('handled_by')->nullable()->constrained('users');
            $t->string('resolution', 300)->nullable();
            $t->timestampsTz();
        });

        Schema::create('product_events', function (Blueprint $t) {
            $t->id();
            $t->string('name', 40)->index();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->jsonb('props')->default('{}');
            $t->timestampTz('created_at')->useCurrent();
        });

        Schema::create('data_exports', function (Blueprint $t) {
            $t->id();
            $t->string('type', 8);
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->string('status', 8)->default('queued');
            $t->string('path')->nullable();
            $t->timestampTz('expires_at')->nullable();
            $t->timestampTz('created_at')->useCurrent();
        });

        Schema::create('feature_flags', function (Blueprint $t) {
            $t->string('key', 50)->primary();
            $t->boolean('enabled')->default(false);
            $t->jsonb('rules')->default('{}');
            $t->string('description', 200);
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('platform_settings', function (Blueprint $t) {
            $t->string('key', 60)->primary();
            $t->jsonb('value');
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestampTz('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['platform_settings', 'feature_flags', 'data_exports', 'product_events',
            'profile_reports', 'audit_logs', 'impersonations', 'notifications'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
