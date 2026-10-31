<?php

use App\Actions\Cards\CreateDigitalCard;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kartu digital: kartu tanpa chip/batch yang langsung aktif — bio link, QR di layar, dan tautan berbagi
 * berfungsi tanpa kartu fisik (mis. kartu ketinggalan atau belum dipesan). Maksimal satu kartu digital
 * yang belum dicabut per pengguna per workspace. Pengguna lama mendapat kartu digital di workspace personal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $t) {
            $t->string('form', 10)->default('physical');
        });
        DB::statement('ALTER TABLE cards ALTER COLUMN chip_type DROP NOT NULL');
        DB::statement('ALTER TABLE cards ALTER COLUMN batch_id DROP NOT NULL');
        DB::statement('ALTER TABLE cards ALTER COLUMN sequence DROP NOT NULL');
        DB::statement("ALTER TABLE cards ADD CONSTRAINT cards_form_chk CHECK (form IN ('physical','digital'))");
        DB::statement("ALTER TABLE cards ADD CONSTRAINT cards_form_shape_chk CHECK (
            (form = 'physical' AND batch_id IS NOT NULL AND chip_type IS NOT NULL AND sequence IS NOT NULL)
            OR (form = 'digital' AND batch_id IS NULL AND chip_type IS NULL AND sequence IS NULL AND chip_uid_hash IS NULL
                AND status IN ('active','paused','revoked'))
        )");
        DB::statement("CREATE UNIQUE INDEX cards_digital_holder_uniq ON cards (tenant_id, holder_user_id)
            WHERE form = 'digital' AND status <> 'revoked'");

        $create = app(CreateDigitalCard::class);
        User::whereNotNull('personal_tenant_id')->whereNull('blocked_at')->orderBy('id')
            ->each(fn (User $u) => $create->handle($u, $u->personalTenant));
    }

    public function down(): void
    {
        DB::table('card_status_history')->whereIn('card_id', DB::table('cards')->where('form', 'digital')->select('id'))->delete();
        DB::table('cards')->where('form', 'digital')->delete();
        DB::statement('DROP INDEX IF EXISTS cards_digital_holder_uniq');
        DB::statement('ALTER TABLE cards DROP CONSTRAINT IF EXISTS cards_form_shape_chk');
        DB::statement('ALTER TABLE cards DROP CONSTRAINT IF EXISTS cards_form_chk');
        Schema::table('cards', fn (Blueprint $t) => $t->dropColumn('form'));
        DB::statement('ALTER TABLE cards ALTER COLUMN chip_type SET NOT NULL');
        DB::statement('ALTER TABLE cards ALTER COLUMN batch_id SET NOT NULL');
        DB::statement('ALTER TABLE cards ALTER COLUMN sequence SET NOT NULL');
    }
};
