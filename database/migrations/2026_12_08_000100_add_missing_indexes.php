<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Index yang belum ada: kolom FK di tabel yang tumbuh (Postgres tidak mengindeks FK otomatis — tanpa ini join,
 * filter, dan hapus/nullOnDelete di tabel induk memindai seluruh tabel anak) + urutan default daftar panel/workspace.
 * CONCURRENTLY: tidak mengunci tulis selama dibangun, wajib di luar transaksi. Bila gagal di tengah, index bisa
 * tertinggal INVALID — hapus dengan DROP INDEX lalu jalankan ulang migrasi.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** nama => [tabel, kolom] */
    private const INDEXES = [
        // FK ke pengguna / tenant / kartu / koneksi
        'idx_users_personal_tenant_id' => ['users', 'personal_tenant_id'],
        'idx_tenants_owner_user_id' => ['tenants', 'owner_user_id'],
        'idx_tenant_slug_history_tenant_id' => ['tenant_slug_history', 'tenant_id'],
        'idx_events_created_by' => ['events', 'created_by'],
        'idx_events_team_id' => ['events', 'team_id'],
        'idx_tenant_members_active_event_id' => ['tenant_members', 'active_event_id'],
        'idx_tenant_members_removed_by' => ['tenant_members', 'removed_by'],
        'idx_tenant_members_role_id' => ['tenant_members', 'role_id'],
        'idx_team_members_user_id' => ['team_members', 'user_id'],
        'idx_profile_fields_tenant_id' => ['profile_fields', 'tenant_id'],
        'idx_batches_design_id' => ['batches', 'design_id'],
        'idx_batches_holder_user_id' => ['batches', 'holder_user_id'],
        'idx_batches_replaces_card_id' => ['batches', 'replaces_card_id'],
        'idx_batches_tenant_id' => ['batches', 'tenant_id'],
        'idx_cards_assigned_user_id' => ['cards', 'assigned_user_id'],
        'idx_cards_profile_id' => ['cards', 'profile_id'],
        'idx_cards_replaces_card_id' => ['cards', 'replaces_card_id'],
        'idx_card_status_history_tenant_id' => ['card_status_history', 'tenant_id'],
        'idx_dynamic_qr_tokens_card_id' => ['dynamic_qr_tokens', 'card_id'],
        'idx_connections_assignee_user_id' => ['connections', 'assignee_user_id'],
        'idx_connections_captured_by_user_id' => ['connections', 'captured_by_user_id'],
        'idx_connections_card_id' => ['connections', 'card_id'],
        'idx_connections_counterpart_card_id' => ['connections', 'counterpart_card_id'],
        'idx_connections_counterpart_user_id' => ['connections', 'counterpart_user_id'],
        'idx_connections_event_id' => ['connections', 'event_id'],
        'idx_connections_merged_into_id' => ['connections', 'merged_into_id'],
        'idx_connections_stage_id' => ['connections', 'stage_id'],
        'idx_connections_team_id' => ['connections', 'team_id'],
        'idx_lead_activities_tenant_id' => ['lead_activities', 'tenant_id'],
        'idx_lead_activities_user_id' => ['lead_activities', 'user_id'],
        'idx_lead_reminders_tenant_id' => ['lead_reminders', 'tenant_id'],
        'idx_impersonations_staff_user_id' => ['impersonations', 'staff_user_id'],
        'idx_impersonations_target_user_id' => ['impersonations', 'target_user_id'],
        'idx_impersonations_tenant_id' => ['impersonations', 'tenant_id'],
        'idx_audit_logs_impersonation_id' => ['audit_logs', 'impersonation_id'],
        'idx_profile_reports_card_id' => ['profile_reports', 'card_id'],
        'idx_profile_reports_profile_id' => ['profile_reports', 'profile_id'],
        'idx_data_exports_user_id' => ['data_exports', 'user_id'],
        'idx_designs_user_id' => ['designs', 'user_id'],
        'idx_lead_imports_user_id' => ['lead_imports', 'user_id'],
        'idx_brand_kits_user_id' => ['brand_kits', 'user_id'],
        'idx_invoices_subscription_id' => ['invoices', 'subscription_id'],
        'idx_invoices_user_id' => ['invoices', 'user_id'],
        'idx_payment_events_tenant_id' => ['payment_events', 'tenant_id'],
        'idx_voucher_redemptions_tenant_id' => ['voucher_redemptions', 'tenant_id'],
        'idx_refunds_invoice_id' => ['refunds', 'invoice_id'],
        'idx_refunds_tenant_id' => ['refunds', 'tenant_id'],
        'idx_payment_reviews_invoice_id' => ['payment_reviews', 'invoice_id'],
        'idx_payment_reviews_tenant_id' => ['payment_reviews', 'tenant_id'],
        'idx_bio_pages_tenant_id' => ['bio_pages', 'tenant_id'],
        'idx_bio_blocks_tenant_id' => ['bio_blocks', 'tenant_id'],
        'idx_event_cards_assigned_by' => ['event_cards', 'assigned_by'],
        'idx_event_cards_tenant_id' => ['event_cards', 'tenant_id'],
        'idx_cart_items_design_id' => ['cart_items', 'design_id'],
        // urutan default daftar (panel: terbaru dulu; workspace: koneksi per tenant terbaru dulu) & pemindaian terjadwal
        'idx_cards_updated_at' => ['cards', 'updated_at'],
        'idx_users_created_at' => ['users', 'created_at'],
        'idx_tenants_type_created_at' => ['tenants', 'type, created_at'],
        'idx_orders_created_at' => ['orders', 'created_at'],
        'idx_invoices_created_at' => ['invoices', 'created_at'],
        'idx_connections_tenant_created_at' => ['connections', 'tenant_id, created_at'],
        'idx_subscriptions_status_period_end' => ['subscriptions', 'status, current_period_end'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => [$table, $cols]) {
            DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS $name ON $table ($cols)");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS $name");
        }
    }
};
