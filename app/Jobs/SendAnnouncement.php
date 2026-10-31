<?php

namespace App\Jobs;

use App\Models\Announcement;
use App\Models\Notification;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Antrean `notify`: tulis notifikasi `announcement` per penerima (bulk insert per 500), lalu catat jumlah penerima. */
class SendAnnouncement implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 600;

    public function __construct(public int $announcementId)
    {
        $this->onQueue('notify');
    }

    public function handle(): void
    {
        $a = Announcement::find($this->announcementId);
        if (! $a || $a->sent_at) {
            return;
        }
        $tenants = match ($a->audience) {
            'personal' => Tenant::where('type', 'personal')->select('id'),
            'company' => Tenant::where('type', '!=', 'personal')->select('id'),
            'plan' => Tenant::where('plan_id', $a->plan_id)->select('id'),
            default => null,
        };
        $users = User::query()->whereNull('blocked_at')
            ->when($tenants, fn ($q) => $q->whereIn('id', fn ($m) => $m->from('tenant_members')->select('user_id')->where('status', 'active')->whereIn('tenant_id', $tenants)));

        $count = 0;
        $users->select('id')->orderBy('id')->chunkById(500, function ($rows) use ($a, &$count) {
            $now = now();
            Notification::insert($rows->map(fn ($u) => [
                'user_id' => $u->id, 'tenant_id' => null, 'type' => 'announcement',
                'data' => json_encode(['id' => $a->public_id, 'title' => $a->title, 'body' => $a->body]), 'created_at' => $now,
            ])->all());
            $count += $rows->count();
        });
        $a->update(['sent_at' => now(), 'recipients' => $count]);
    }
}
