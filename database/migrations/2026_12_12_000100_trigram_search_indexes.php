<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Index trigram (pg_trgm, GIN) untuk pencarian `ilike '%q%'` di tabel yang terus tumbuh — tanpa ini setiap kotak cari
 * memindai seluruh tabel. Hanya kolom yang memang dicari dengan wildcard di depan (Table::paginate, panel & workspace).
 * pg_trgm extension "trusted" sejak PG13: pemilik database boleh memasangnya. Bila gagal (hak akses), index dilewati
 * dengan pesan — pencarian tetap jalan, hanya lebih lambat. CONCURRENTLY: tidak mengunci tulis; wajib di luar transaksi.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** nama => [tabel, kolom] */
    private const INDEXES = [
        'trgm_users_name' => ['users', 'name'],
        'trgm_users_email' => ['users', 'email'],
        'trgm_users_handle' => ['users', 'handle'],
        'trgm_tenants_name' => ['tenants', 'name'],
        'trgm_tenants_slug' => ['tenants', 'slug'],
        'trgm_connections_name' => ['connections', 'name'],
        'trgm_connections_company' => ['connections', 'company'],
        'trgm_connections_email' => ['connections', 'email'],
        'trgm_connections_phone' => ['connections', 'phone_e164'],
        // card_token & short_code (char) tidak diindeks: pencariannya selalu sudah dipersempit per workspace/batch/pengguna
        'trgm_cards_label' => ['cards', 'label'],
        'trgm_orders_number' => ['orders', 'number'],
        'trgm_orders_recipient' => ['orders', 'recipient_name'],
        'trgm_invoices_number' => ['invoices', 'number'],
    ];

    public function up(): void
    {
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        } catch (Throwable $e) {
            fwrite(STDERR, "  ⚠ pg_trgm tidak bisa dipasang ({$e->getMessage()}). Index pencarian dilewati. Setelah admin database menjalankan CREATE EXTENSION pg_trgm: php artisan migrate:refresh --path=database/migrations/2026_12_12_000100_trigram_search_indexes.php\n");

            return;
        }
        foreach (self::INDEXES as $name => [$table, $col]) {
            DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS $name ON $table USING gin ($col gin_trgm_ops)");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS $name");
        }
    }
};
