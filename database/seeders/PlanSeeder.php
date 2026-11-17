<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * PRD §11 — harga placeholder; semua angka bisa diubah dari panel pemilik.
 * Personal: bulanan/tahunan. Perusahaan: langganan workspace per 6 bulan, harga tetap, dibedakan batas kartu aktif;
 * anggota tidak dibatasi. Kartu fisik dibeli terpisah lewat pesanan.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $none = [
            'subdomain' => false, 'teams' => false, 'custom_roles' => false, 'custom_stages' => false,
            'lead_fields' => false, 'auto_assign' => false, 'webhooks' => false, 'dynamic_qr' => false,
            'events_checkin' => false, 'custom_domain' => false, 'event_mode' => true, 'pipeline' => true, 'reminders' => true,
        ];
        $company = ['subdomain' => true, 'teams' => true, 'dynamic_qr' => true, 'events_checkin' => true];
        $business = [...$company, 'custom_roles' => true, 'custom_stages' => true, 'lead_fields' => true,
            'auto_assign' => true, 'webhooks' => true, 'custom_domain' => true];
        $companyLimits = fn (?int $cards, int $analytics, int $audit) => ['members' => null, 'cards_active' => $cards, 'profiles_per_user' => 3,
            'connections' => null, 'analytics_days' => $analytics, 'audit_days' => $audit];

        // [key, tipe, nama, bulanan, 6 bulan, batas, fitur]
        $plans = [
            ['personal_free', 'personal', 'Personal Free', 0, 0,
                ['members' => 1, 'cards_active' => 2, 'profiles_per_user' => 1, 'connections' => 100, 'analytics_days' => 30, 'audit_days' => 0, 'qr_rotation_days' => 7],
                [...$none, 'reminders' => false]],
            ['personal_pro', 'personal', 'Personal Pro', 49000, 0,
                ['members' => 1, 'cards_active' => 10, 'profiles_per_user' => 5, 'connections' => null, 'analytics_days' => 365, 'audit_days' => 0],
                [...$none, 'dynamic_qr' => true]],
            ['starter', 'company', 'Starter', 0, 300000, $companyLimits(25, 365, 30), [...$none, ...$company]],
            ['growth', 'company', 'Growth', 0, 900000, $companyLimits(100, 730, 365), [...$none, ...$company]],
            ['business', 'company', 'Business', 0, 2500000, $companyLimits(500, 730, 365), [...$none, ...$business]],
            ['enterprise', 'company', 'Enterprise', 0, 0, $companyLimits(null, 730, 730), [...$none, ...$business]],
        ];

        foreach ($plans as $sort => [$key, $type, $name, $monthly, $semiannual, $limits, $features]) {
            Plan::updateOrCreate(['key' => $key], [
                'tenant_type' => $type,
                'name' => $name,
                'price_monthly' => $monthly,
                'price_yearly' => $monthly * 10,
                'price_semiannual' => $semiannual,
                'per_seat' => false,
                'limits' => $limits,
                'features' => $features,
                'trial_days' => $type === 'company' ? 14 : 0,
                'is_public' => $key !== 'enterprise',
                'sort' => $sort,
            ]);
        }
    }
}
