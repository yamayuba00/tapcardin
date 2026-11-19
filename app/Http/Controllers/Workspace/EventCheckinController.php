<?php

namespace App\Http\Controllers\Workspace;

use App\Actions\Cards\CardView;
use App\Exceptions\ApiError;
use App\Models\Event;
use App\Models\EventCheckin;
use App\Support\Audit;
use App\Support\Http\Table;
use App\Support\Webhooks\WebhookPayload;
use App\Support\Webhooks\Webhooks;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Check-in peserta event oleh panitia: tap NFC / scan QR / ketik kode kartu → tercatat hadir sekali per kartu.
 * Kartu milik workspace lain tetap bisa check-in sebagai tamu: hanya nama publik profilnya yang disimpan.
 */
class EventCheckinController extends WorkspaceController
{
    /** POST /api/events/{event}/checkins {value, method} */
    public function store(Request $request, Event $event): JsonResponse
    {
        $this->access()->authorize('events.checkin');
        $data = $request->validate(['value' => ['required', 'string', 'max:500'], 'method' => ['required', Rule::in(['nfc', 'qr', 'code'])]]);
        $token = ScanController::tokenFrom($data['value']);
        $view = $token ? CardView::cached($token) : null;
        if (! $view) {
            throw new ApiError('card_not_found', 'Kartu tidak dikenal. Pastikan ini kartu TapCard, atau ketik kode yang tercetak di kartu.');
        }
        if ($view->status !== 'active' || ! $view->profile) {
            throw new ApiError('card_inactive', 'Kartu ini belum aktif atau sedang dinonaktifkan, jadi tidak bisa dipakai check-in.');
        }

        $existing = EventCheckin::with('staff')->where('event_id', $event->id)->where('card_id', $view->cardId)->first();
        if ($existing) {
            return response()->json(['data' => ['status' => 'duplicate', 'checkin' => self::row($existing), 'count' => $this->count($event)]]);
        }
        $own = $view->tenantId === $event->tenant_id;
        try {
            $checkin = EventCheckin::create([
                'event_id' => $event->id, 'card_id' => $view->cardId,
                'user_id' => $own ? $view->holderUserId : null,
                'guest_name' => $own ? null : mb_substr((string) $view->profile['display_name'], 0, 120),
                'method' => $data['method'], 'checked_in_by' => $this->user()->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            // dua panitia memindai kartu yang sama di detik yang sama
            $checkin = EventCheckin::where('event_id', $event->id)->where('card_id', $view->cardId)->firstOrFail();

            return response()->json(['data' => ['status' => 'duplicate', 'checkin' => self::row($checkin->load('staff')), 'count' => $this->count($event)]]);
        }
        $checkin->load(['user', 'card', 'staff']);
        Webhooks::emit($event->tenant_id, 'event.checkin', fn () => self::hook($event, $checkin));

        return response()->json(['data' => ['status' => 'checked_in', 'checkin' => self::row($checkin), 'count' => $this->count($event)]], 201);
    }

    /** GET /api/events/{event}/checkins */
    public function index(Request $request, Event $event): JsonResponse
    {
        $this->access()->authorize('events.checkin', 'events.manage.*');
        $q = EventCheckin::with(['user', 'staff'])->where('event_id', $event->id);

        return Table::json(Table::paginate($q, $request, ['at' => 'created_at'], '-at', [], 25), fn (EventCheckin $c) => self::row($c), $request);
    }

    /** GET /api/events/{event}/checkins.csv */
    public function export(Event $event): StreamedResponse
    {
        $this->access()->authorize('events.checkin', 'events.manage.*');
        $q = EventCheckin::with(['user', 'card', 'staff'])->where('event_id', $event->id)->orderBy('created_at');

        return response()->streamDownload(function () use ($q) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM: Excel membaca UTF-8 dengan benar
            fputcsv($out, ['Nama', 'Email', 'Tamu', 'Kode kartu', 'Cara', 'Dicatat oleh', 'Waktu']);
            $q->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $c) {
                    fputcsv($out, [self::name($c), $c->user?->email ?? '', $c->user_id ? 'tidak' : 'ya', $c->card?->formattedShortCode() ?? '',
                        $c->method, $c->staff?->name ?? '', $c->created_at?->timezone('Asia/Jakarta')->format('Y-m-d H:i')]);
                }
            });
            fclose($out);
        }, 'kehadiran-'.$event->public_id.'.csv', ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    /** DELETE /api/events/{event}/checkins/{checkin} — batalkan check-in yang salah (owner/admin). */
    public function destroy(Event $event, string $checkin): JsonResponse
    {
        $this->access()->authorize('events.manage.all');
        $c = EventCheckin::where('event_id', $event->id)->where('public_id', $checkin)->firstOrFail();
        Audit::tenant($event->tenant_id, 'event.checkin_removed', $c, ['name' => self::name($c->load('user'))]);
        $c->delete();

        return response()->json(null, 204);
    }

    private function count(Event $event): int
    {
        return EventCheckin::where('event_id', $event->id)->count();
    }

    private static function name(EventCheckin $c): string
    {
        return $c->user?->name ?? $c->guest_name ?? 'Peserta';
    }

    public static function row(EventCheckin $c): array
    {
        return [
            'id' => $c->public_id, 'name' => self::name($c), 'guest' => $c->user_id === null,
            'method' => $c->method, 'by' => $c->staff ? ['name' => $c->staff->name] : null, 'at' => $c->created_at?->toIso8601String(),
        ];
    }

    /** Payload webhook: peserta tamu dari workspace lain hanya nama publik + kartu, tanpa akun. */
    public static function hook(Event $event, EventCheckin $c): array
    {
        return [
            'event' => ['id' => $event->public_id, 'name' => $event->name],
            'attendee' => $c->user ? WebhookPayload::user($c->user) + ['guest' => false] : ['id' => null, 'name' => $c->guest_name, 'email' => null, 'guest' => true],
            'card' => $c->user && $c->card ? WebhookPayload::cardRef($c->card) : null,
            'method' => $c->method, 'checked_in_by' => WebhookPayload::user($c->staff), 'at' => $c->created_at?->toIso8601String(),
        ];
    }
}
