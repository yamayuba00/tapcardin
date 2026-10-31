<?php

namespace App\Actions\Leads;

use App\Models\Connection;
use App\Models\LeadReminder;
use App\Models\Tenant;

/**
 * LD-05 / TRD §10.7 — pengingat follow-up:
 *  - lead baru → pengingat otomatis `auto_followup_hours` (pengaturan workspace, 0 = mati);
 *  - aktivitas pertama (telepon, WA, catatan, pindah tahap) membatalkan pengingat otomatis;
 *  - `connections.next_followup_at` selalu = pengingat terdekat yang belum selesai (untuk filter & urut).
 */
final class Followups
{
    public static function auto(Connection $c, Tenant $tenant): ?LeadReminder
    {
        $hours = (int) $tenant->setting('auto_followup_hours');
        $user = $c->assignee_user_id ?? $c->captured_by_user_id;
        if ($hours <= 0 || ! $user) {
            return null;
        }
        $r = LeadReminder::create([
            'connection_id' => $c->id, 'user_id' => $user, 'due_at' => now()->addHours($hours),
            'note' => 'Follow-up otomatis', 'is_auto' => true,
        ]);
        self::sync($c);

        return $r;
    }

    public static function cancelAuto(Connection $c): void
    {
        LeadReminder::where('connection_id', $c->id)->where('is_auto', true)->whereNull('done_at')->update(['done_at' => now()]);
        self::sync($c);
    }

    public static function sync(Connection $c): void
    {
        $next = LeadReminder::where('connection_id', $c->id)->whereNull('done_at')->min('due_at');
        Connection::whereKey($c->id)->update(['next_followup_at' => $next]);
        $c->next_followup_at = $next;
    }
}
