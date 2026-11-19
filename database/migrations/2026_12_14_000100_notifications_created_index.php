<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** tapcard:prune menghapus notifikasi lama per batch urut created_at — tanpa indeks ini tiap batch memindai seluruh tabel. */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS notifications_created_ix ON notifications (created_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS notifications_created_ix');
    }
};
