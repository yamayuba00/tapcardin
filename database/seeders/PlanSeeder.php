<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/** PRD §11 — harga placeholder sampai model bisnis diputuskan; semua angka bisa diubah dari panel pemilik. */
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

        $plans = [
            ['personal_free', 'personal', 'Personal Free', 0, false,
                ['members' => 1, 'cards_active' => 2, 'profiles_per_user' => 1, 'connections' => 200, 'analytics_days' => 30, 'audit_days' => 0],
                [...$none, 'reminders' => false]],
            ['personal_pro', 'personal', 'Personal Pro', 49000, false,
                ['members' => 1, 'cards_active' => 10, 'profiles_per_user' => 5, 'connections' => null, 'analytics_days' => 365, 'audit_days' => 0],
                [...$none, 'dynamic_qr' => true]],
            ['team', 'company', 'Team', 39000, true,
                ['members' => 10, 'cards_per_member' => 2, 'profiles_per_user' => 2, 'connections' => null, 'analytics_days' => 365, 'audit_days' => 30],
                [...$none, ...$company]],
            ['business', 'company', 'Business', 69000, true,
                ['members' => 200, 'cards_per_member' => 3, 'profiles_per_user' => 3, 'connections' => null, 'analytics_days' => 730, 'audit_days' => 365],
                [...$none, ...$business]],
            ['enterprise', 'company', 'Enterprise', 0, true,
                ['members' => null, 'cards_per_member' => null, 'profiles_per_user' => 5, 'connections' => null, 'analytics_days' => 730, 'audit_days' => 730],
                [...$none, ...$business]],
        ];

        foreach ($plans as $sort => [$key, $type, $name, $monthly, $perSeat, $limits, $features]) {
            Plan::updateOrCreate(['key' => $key], [
                'tenant_type' => $type,
                'name' => $name,
                'price_monthly' => $monthly,
                'price_yearly' => $monthly * 10,
                'per_seat' => $perSeat,
                'limits' => $limits,
                'features' => $features,
                'trial_days' => $type === 'company' ? 14 : 0,
                'is_public' => $key !== 'enterprise',
                'sort' => $sort,
            ]);
        }
    }
}
