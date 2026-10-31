<?php

namespace App\Console\Commands;

use App\Models\LeadReminder;
use App\Support\Notify;
use Illuminate\Console\Command;

/** LD-05 / BSD `ReminderDue` — tiap menit: pengingat yang jatuh tempo → notifikasi in-app (SSE) sekali saja. */
class DueReminders extends Command
{
    protected $signature = 'tapcard:reminders';

    protected $description = 'Kirim notifikasi pengingat follow-up yang jatuh tempo';

    public function handle(): int
    {
        $sent = 0;
        LeadReminder::acrossTenants()->with(['connection' => fn ($q) => $q->withoutGlobalScopes()])
            ->whereNull('done_at')->whereNull('notified_at')->where('due_at', '<=', now())
            ->orderBy('id')->chunkById(200, function ($rows) use (&$sent) {
                foreach ($rows as $r) {
                    // klaim dulu agar dua scheduler yang tumpang tindih tidak mengirim ganda
                    if (! LeadReminder::acrossTenants()->whereKey($r->id)->whereNull('notified_at')->update(['notified_at' => now()])) {
                        continue;
                    }
                    if (! $r->connection || $r->connection->deleted_at) {
                        continue;
                    }
                    Notify::user($r->user_id, $r->tenant_id, 'reminder_due', [
                        'id' => $r->connection->public_id, 'name' => $r->connection->name,
                        'company' => $r->connection->company, 'note' => $r->note, 'reminder' => $r->public_id,
                    ]);
                    $sent++;
                }
            });
        $this->info("$sent pengingat dikirim.");

        return self::SUCCESS;
    }
}
