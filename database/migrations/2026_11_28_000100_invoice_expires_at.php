<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batas waktu bayar invoice yang dibuat pengguna (checkout paket, pesanan, tambahan tap): lewat `expires_at` →
 * dibatalkan otomatis (tapcard:expire-invoices). Invoice perpanjangan otomatis (user_id null) tanpa batas ini —
 * jatuh temponya akhir periode langganan. Invoice terbuka yang sudah ada diberi batas dari waktu dibuatnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->timestampTz('expires_at')->nullable();
            $t->index(['status', 'expires_at']);
        });
        $ttl = (int) config('payment.invoice_ttl_minutes', 30);
        DB::table('invoices')->where('status', 'issued')->whereNotNull('user_id')
            ->update(['expires_at' => DB::raw("created_at + interval '$ttl minutes'"), 'due_at' => DB::raw("created_at + interval '$ttl minutes'")]);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropIndex(['status', 'expires_at']);
            $t->dropColumn('expires_at');
        });
    }
};
