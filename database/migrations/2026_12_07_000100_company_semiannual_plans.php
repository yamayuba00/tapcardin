<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Paket perusahaan = langganan workspace/subdomain per 6 bulan, harga tetap per paket (bukan per anggota),
 * dibedakan oleh batas kartu aktif. team → starter (ganti di tempat agar FK tetap), tambah growth.
 */
return new class extends Migration
{
    private const COMPANY = [
        // key => [nama, kartu aktif, harga 6 bulan, sort]
        'starter' => ['Starter', 25, 300000, 2],
        'growth' => ['Growth', 100, 900000, 3],
        'business' => ['Business', 500, 2500000, 4],
        'enterprise' => ['Enterprise', null, 0, 5],
    ];

    public function up(): void
    {
        Schema::table('plans', fn (Blueprint $t) => $t->bigInteger('price_semiannual')->default(0)->after('price_yearly'));
        DB::statement('ALTER TABLE subscriptions DROP CONSTRAINT subscriptions_cycle_chk');
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_cycle_chk CHECK (cycle IN ('monthly','yearly','semiannual'))");

        DB::table('plans')->where('key', 'team')->update(['key' => 'starter']);
        $template = DB::table('plans')->where('key', 'starter')->first();
        if ($template && ! DB::table('plans')->where('key', 'growth')->exists()) {
            DB::table('plans')->insert(collect((array) $template)->except('id')->merge(['key' => 'growth', 'created_at' => now(), 'updated_at' => now()])->all());
        }
        foreach (self::COMPANY as $key => [$name, $cards, $price, $sort]) {
            $plan = DB::table('plans')->where('key', $key)->first();
            if (! $plan) {
                continue;
            }
            $limits = array_diff_key(json_decode($plan->limits, true) ?? [], ['cards_per_member' => 1]);
            DB::table('plans')->where('id', $plan->id)->update([
                'name' => $name, 'price_monthly' => 0, 'price_yearly' => 0, 'price_semiannual' => $price, 'per_seat' => false,
                'trial_days' => 14, 'sort' => $sort, 'limits' => json_encode(['members' => null, 'cards_active' => $cards] + $limits),
            ]);
        }
        // langganan perusahaan yang berjalan pindah ke siklus 6 bulan; tanggal berakhir tidak berubah
        DB::table('subscriptions')->whereIn('plan_id', DB::table('plans')->where('tenant_type', 'company')->select('id'))->update(['cycle' => 'semiannual']);
    }

    public function down(): void
    {
        $company = DB::table('plans')->where('tenant_type', 'company')->select('id');
        DB::table('subscriptions')->whereIn('plan_id', $company)->update(['cycle' => 'monthly']);
        $growth = DB::table('plans')->where('key', 'growth')->value('id');
        if ($growth && ! DB::table('tenants')->where('plan_id', $growth)->exists() && ! DB::table('subscriptions')->where('plan_id', $growth)->exists()
            && ! DB::table('invoices')->where('plan_id', $growth)->exists()) {
            DB::table('plans')->where('id', $growth)->delete();
        }
        DB::table('plans')->where('key', 'starter')->update(['key' => 'team', 'name' => 'Team']);
        DB::statement('ALTER TABLE subscriptions DROP CONSTRAINT subscriptions_cycle_chk');
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_cycle_chk CHECK (cycle IN ('monthly','yearly'))");
        Schema::table('plans', fn (Blueprint $t) => $t->dropColumn('price_semiannual'));
    }
};
