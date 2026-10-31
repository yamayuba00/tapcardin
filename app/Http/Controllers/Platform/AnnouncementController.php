<?php

namespace App\Http\Controllers\Platform;

use App\Jobs\SendAnnouncement;
use App\Models\Announcement;
use App\Models\Plan;
use App\Support\Audit;
use App\Support\Http\Table;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Pengumuman (platform.comms.manage) → notifikasi in-app `announcement` ke semua pengguna / tipe workspace / paket. */
class AnnouncementController extends PlatformController
{
    public function index(Request $request): JsonResponse
    {
        $this->can('comms.manage');

        return Table::json(Table::paginate(Announcement::with('plan'), $request, ['created_at' => 'created_at'], '-created_at', ['title'], 20),
            fn (Announcement $a) => self::present($a), $request);
    }

    public function store(Request $request): JsonResponse
    {
        $this->can('comms.manage');
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:500'],
            'audience' => ['required', Rule::in(['all', 'personal', 'company', 'plan'])],
            'plan' => ['required_if:audience,plan', 'nullable', Rule::exists('plans', 'key')],
        ]);
        $a = Announcement::create([
            'title' => $data['title'], 'body' => $data['body'], 'audience' => $data['audience'],
            'plan_id' => $data['audience'] === 'plan' ? Plan::where('key', $data['plan'])->value('id') : null, 'created_by' => auth()->id(),
        ]);
        SendAnnouncement::dispatch($a->id);
        Audit::platform('announcement.sent', $a, null, ['title' => $a->title, 'audience' => $a->audience]);

        return $this->ok(self::present($a->fresh('plan')), 201);
    }

    public static function present(Announcement $a): array
    {
        return [
            'id' => $a->public_id, 'title' => $a->title, 'body' => $a->body, 'audience' => $a->audience, 'plan' => $a->plan?->name,
            'recipients' => $a->recipients, 'sent_at' => self::dt($a->sent_at), 'created_at' => self::dt($a->created_at),
        ];
    }
}
