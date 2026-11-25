<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Invoice perpanjangan masa pakai kartu (`card_renewal`). */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE invoices DROP CONSTRAINT invoices_type_chk');
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_type_chk CHECK (type IN ('order','subscription','adjustment','topup','card_renewal'))");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM invoices WHERE type = 'card_renewal'");
        DB::statement('ALTER TABLE invoices DROP CONSTRAINT invoices_type_chk');
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_type_chk CHECK (type IN ('order','subscription','adjustment','topup'))");
    }
};
