<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $t) {
            $t->id();
            $t->string('key', 30)->unique();
            $t->string('tenant_type', 16);
            $t->string('name', 60);
            $t->bigInteger('price_monthly')->default(0);
            $t->bigInteger('price_yearly')->default(0);
            $t->boolean('per_seat')->default(false);
            $t->jsonb('limits')->default('{}');
            $t->jsonb('features')->default('{}');
            $t->smallInteger('trial_days')->default(0);
            $t->boolean('is_public')->default(true);
            $t->smallInteger('sort')->default(0);
            $t->timestampsTz();
        });

        Schema::create('tenants', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->string('type', 16);
            $t->string('name', 160);
            $t->string('slug', 30)->nullable()->unique();
            $t->foreignId('owner_user_id')->constrained('users');
            $t->string('status', 20)->default('active')->index();
            $t->string('logo_path')->nullable();
            $t->jsonb('brand')->default('{}');
            $t->unsignedBigInteger('template_design_id')->nullable();
            $t->jsonb('locked_fields')->default('[]');
            $t->jsonb('settings')->default('{}');
            $t->jsonb('billing_profile')->default('{}');
            $t->foreignId('plan_id')->constrained('plans');
            $t->jsonb('limit_overrides')->default('{}');
            $t->jsonb('price_overrides')->default('{}');
            $t->timestampTz('suspended_at')->nullable();
            $t->string('suspend_reason', 200)->nullable();
            $t->timestampTz('deletion_requested_at')->nullable();
            $t->text('internal_note')->nullable();
            $t->timestampsTz();
            $t->softDeletesTz();
        });
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_type_chk CHECK (type IN ('personal','company','partner'))");
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_status_chk CHECK (status IN ('active','trialing','past_due','suspended','pending_deletion','deleted'))");
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_slug_chk CHECK
            (slug IS NULL OR slug ~ '^[a-z0-9]([a-z0-9-]{1,28})[a-z0-9]$')");
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_company_slug_chk CHECK
            (type = 'personal' OR slug IS NOT NULL)");

        Schema::table('users', function (Blueprint $t) {
            $t->foreign('personal_tenant_id')->references('id')->on('tenants');
        });

        Schema::create('tenant_slug_history', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('slug', 30)->index();
            $t->timestampTz('replaced_at');
            $t->timestampTz('redirect_until');
        });

        Schema::create('reserved_slugs', function (Blueprint $t) {
            $t->string('slug', 30)->primary();
            $t->string('reason', 60);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reserved_slugs');
        Schema::dropIfExists('tenant_slug_history');
        Schema::table('users', fn (Blueprint $t) => $t->dropForeign(['personal_tenant_id']));
        Schema::dropIfExists('tenants');
        Schema::dropIfExists('plans');
    }
};
