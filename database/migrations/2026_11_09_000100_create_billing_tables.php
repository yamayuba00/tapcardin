<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BSD §4 — langganan paket workspace, invoice, dan event pembayaran gateway (idempoten per provider+external_id).
 * Satu langganan per workspace; invoice upgrade/perpanjangan menunjuk paket & siklus tujuan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->foreignId('tenant_id')->unique()->constrained();
            $t->foreignId('plan_id')->constrained('plans');
            $t->string('cycle', 10);
            $t->unsignedInteger('seats')->default(1);
            $t->string('status', 12)->index();
            $t->timestampTz('trial_ends_at')->nullable();
            $t->timestampTz('current_period_start');
            $t->timestampTz('current_period_end')->index();
            $t->timestampTz('grace_until')->nullable();
            $t->boolean('cancel_at_period_end')->default(false);
            $t->timestampTz('cancelled_at')->nullable();
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_cycle_chk CHECK (cycle IN ('monthly','yearly'))");
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_status_chk CHECK (status IN ('trialing','active','past_due','cancelled','expired'))");

        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // yang membuat / membayar
            $t->string('number', 24)->unique();
            $t->string('type', 14);
            $t->string('status', 10);
            $t->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('plan_id')->nullable()->constrained('plans'); // paket tujuan (langganan)
            $t->string('cycle', 10)->nullable();
            $t->jsonb('lines');
            $t->bigInteger('subtotal');
            $t->bigInteger('discount')->default(0);
            $t->bigInteger('tax')->default(0);
            $t->bigInteger('total');
            $t->jsonb('billing_snapshot')->default('{}');
            $t->timestampTz('issued_at')->nullable();
            $t->timestampTz('due_at')->nullable();
            $t->timestampTz('paid_at')->nullable();
            $t->string('payment_provider', 16)->nullable();
            $t->string('payment_ref', 80)->nullable()->unique();
            $t->string('payment_method', 40)->nullable();
            $t->string('payment_url', 500)->nullable();
            $t->string('pdf_path')->nullable();
            $t->timestampsTz();
            $t->index(['tenant_id', 'status']);
            $t->index(['tenant_id', 'created_at']);
            $t->index(['status', 'due_at']);
        });
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_type_chk CHECK (type IN ('order','subscription','adjustment'))");
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_status_chk CHECK (status IN ('draft','issued','paid','overdue','void'))");

        Schema::create('payment_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $t->string('provider', 16);
            $t->string('external_id', 120);
            $t->string('type', 40);
            $t->bigInteger('amount')->nullable();
            $t->jsonb('payload');
            $t->boolean('signature_valid');
            $t->timestampTz('reconciled_at')->nullable();
            $t->timestampTz('processed_at')->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['provider', 'external_id']);
            $t->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('subscriptions');
    }
};
