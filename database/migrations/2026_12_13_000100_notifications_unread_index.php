<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Hitung notifikasi belum dibaca (lencana lonceng di setiap halaman) tanpa memindai semua notifikasi pengguna. */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS notifications_unread_ix ON notifications (user_id, tenant_id) WHERE read_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS notifications_unread_ix');
    }
};
