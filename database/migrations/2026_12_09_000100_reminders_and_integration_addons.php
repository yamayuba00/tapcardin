<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * 1) `subscriptions.reminders_sent`: penanda pengingat H-30 / H-7 / H-1 yang sudah terkirim per periode (kunci memuat
 *    tanggal akhir periode, jadi periode baru otomatis mulai bersih).
 * 2) Add-on dikelompokkan (`group`): "Integrasi CRM" dipecah per alat (semua segera hadir) + Webhook segera hadir.
 *    Pendaftar minat CRM lama dipindah ke "Lainnya" beserta catatannya; add-on lama dipensiunkan.
 */
return new class extends Migration
{
    private const CRM = [
        ['whatsapp', 'WhatsApp', 'Kirim koneksi baru & pengingat follow-up langsung ke WhatsApp tim Anda.',
            ['Notifikasi lead baru ke nomor/grup WhatsApp', 'Template pesan follow-up', 'Kirim kartu nama digital lewat chat']],
        ['telegram', 'Telegram', 'Kabar lead baru & tap kartu masuk ke chat atau grup Telegram.',
            ['Bot notifikasi lead baru', 'Ringkasan harian ke grup', 'Tanpa biaya pesan']],
        ['hubspot', 'HubSpot', 'Kontak & deal baru otomatis dibuat di HubSpot.',
            ['Kontak & deal otomatis', 'Pemetaan kolom & tahap pipeline', 'Riwayat sinkron & kirim ulang bila gagal']],
        ['google_sheets', 'Google Sheets', 'Setiap koneksi baru otomatis menjadi baris di spreadsheet Anda.',
            ['Satu baris per koneksi', 'Pilih kolom yang dikirim', 'Cocok untuk tim tanpa CRM']],
        ['zoho', 'Zoho CRM', 'Lead dari kartu masuk ke modul Leads di Zoho CRM.',
            ['Lead & kontak otomatis', 'Pemetaan kolom', 'Pemilik lead sesuai karyawan']],
        ['salesforce', 'Salesforce', 'Lead dari kartu masuk ke Salesforce dengan pemilik yang tepat.',
            ['Lead otomatis', 'Pemetaan kolom & owner', 'Riwayat sinkron']],
        ['crm_other', 'CRM lainnya', 'Pakai alat lain? Beri tahu kami alat yang Anda pakai agar kami prioritaskan.',
            ['Tulis nama alat Anda saat mendaftar', 'Kami kabari begitu tersedia']],
    ];

    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $t) {
            $t->jsonb('reminders_sent')->nullable();
        });
        Schema::table('addons', function (Blueprint $t) {
            $t->string('group', 16)->default('addon');
        });

        $now = now();
        foreach (self::CRM as $i => [$key, $name, $desc, $features]) {
            DB::table('addons')->insert(['public_id' => (string) Str::ulid(), 'key' => $key, 'name' => $name, 'description' => $desc,
                'features' => json_encode($features), 'status' => 'coming_soon', 'group' => 'crm', 'sort' => 10 + $i,
                'created_at' => $now, 'updated_at' => $now]);
        }
        DB::table('addons')->insert(['public_id' => (string) Str::ulid(), 'key' => 'webhooks', 'name' => 'Webhook',
            'description' => 'Kirim event workspace (lead baru, lead diperbarui, tap kartu, karyawan baru) ke URL sistem Anda secara realtime.',
            'features' => json_encode(['Pilih event yang dikirim', 'Tanda tangan HMAC untuk keamanan', 'Uji kirim & log pengiriman', 'Kirim ulang otomatis bila gagal']),
            'status' => 'coming_soon', 'group' => 'webhook', 'sort' => 50, 'created_at' => $now, 'updated_at' => $now]);

        $old = DB::table('addons')->where('key', 'crm')->value('id');
        $other = DB::table('addons')->where('key', 'crm_other')->value('id');
        if ($old) {
            DB::table('addon_interests')->where('addon_id', $old)->update(['addon_id' => $other]);
            DB::table('addons')->where('id', $old)->update(['status' => 'retired', 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        $old = DB::table('addons')->where('key', 'crm')->value('id');
        $other = DB::table('addons')->where('key', 'crm_other')->value('id');
        if ($old && $other) {
            DB::table('addon_interests')->where('addon_id', $other)->update(['addon_id' => $old]);
            DB::table('addons')->where('id', $old)->update(['status' => 'coming_soon']);
        }
        DB::table('addons')->whereIn('key', [...array_column(self::CRM, 0), 'webhooks'])->delete();
        Schema::table('addons', fn (Blueprint $t) => $t->dropColumn('group'));
        Schema::table('subscriptions', fn (Blueprint $t) => $t->dropColumn('reminders_sent'));
    }
};
