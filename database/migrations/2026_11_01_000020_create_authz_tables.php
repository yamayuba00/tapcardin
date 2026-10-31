<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $t) {
            $t->string('key', 60)->primary();
            $t->string('scope', 10);
            $t->string('area', 30);
            $t->string('label', 120);
            $t->string('plan_feature', 40)->nullable();
        });

        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->string('scope', 10);
            $t->foreignId('tenant_id')->nullable()->constrained();
            $t->string('key', 40)->nullable();
            $t->string('name', 60);
            $t->string('description', 200)->nullable();
            $t->boolean('is_system')->default(false);
            $t->foreignId('based_on_role_id')->nullable()->constrained('roles');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'name']);
        });
        DB::statement('CREATE UNIQUE INDEX roles_system_key_uq ON roles (key) WHERE is_system');

        Schema::create('role_permissions', function (Blueprint $t) {
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->string('permission_key', 60);
            $t->foreign('permission_key')->references('key')->on('permissions')->cascadeOnDelete();
            $t->primary(['role_id', 'permission_key']);
        });

        Schema::create('events', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('created_by')->constrained('users');
            $t->unsignedBigInteger('team_id')->nullable();
            $t->string('name', 120);
            $t->string('location', 160)->nullable();
            $t->timestampTz('starts_at');
            $t->timestampTz('ends_at');
            $t->boolean('checkin_enabled')->default(false);
            $t->jsonb('settings')->default('{}');
            $t->timestampsTz();
            $t->index(['tenant_id', 'starts_at']);
        });

        Schema::create('tenant_members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('role_id')->constrained('roles');
            $t->string('status', 10)->default('active');
            $t->string('title', 120)->nullable();
            $t->string('handle', 30)->nullable();
            $t->boolean('show_in_directory')->default(false);
            $t->foreignId('active_event_id')->nullable()->constrained('events')->nullOnDelete();
            $t->timestampTz('active_event_until')->nullable();
            $t->timestampTz('joined_at')->useCurrent();
            $t->timestampTz('removed_at')->nullable();
            $t->foreignId('removed_by')->nullable()->constrained('users');
            $t->unique(['tenant_id', 'user_id']);
            $t->unique(['tenant_id', 'handle']);
            $t->index('user_id');
        });
        DB::statement("ALTER TABLE tenant_members ADD CONSTRAINT tenant_members_status_chk CHECK (status IN ('active','removed'))");

        Schema::create('teams', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->foreignId('tenant_id')->constrained();
            $t->string('name', 80);
            $t->string('description', 200)->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'name']);
        });

        Schema::table('events', function (Blueprint $t) {
            $t->foreign('team_id')->references('id')->on('teams')->nullOnDelete();
        });

        Schema::create('team_members', function (Blueprint $t) {
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('team_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained();
            $t->boolean('is_manager')->default(false);
            $t->timestampTz('created_at')->useCurrent();
            $t->primary(['team_id', 'user_id']);
            $t->index(['tenant_id', 'user_id']);
        });

        Schema::create('platform_staff', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained();
            $t->foreignId('role_id')->constrained('roles');
            $t->string('status', 10)->default('active');
            $t->foreignId('invited_by')->nullable()->constrained('users');
            $t->timestampTz('last_reverified_at')->nullable();
            $t->jsonb('ip_allowlist')->nullable();
            $t->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_staff');
        Schema::dropIfExists('team_members');
        Schema::table('events', fn (Blueprint $t) => $t->dropForeign(['team_id']));
        Schema::dropIfExists('teams');
        Schema::dropIfExists('tenant_members');
        Schema::dropIfExists('events');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
