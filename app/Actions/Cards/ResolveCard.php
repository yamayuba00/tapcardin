<?php

namespace App\Actions\Cards;

use App\Actions\Events\EventForCard;
use App\Http\Resources\PublicProfile;
use App\Jobs\RecordTap;
use App\Jobs\TapContext;
use App\Models\Event;
use App\Support\Hasher;
use App\Support\UserAgent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * PRD §8 / TRD §6.2 — apa yang dilihat pengunjung saat kartu di-tap, sekaligus mencatat tap.
 * Dipakai oleh shell `/c/{token}` (data disisipkan ke HTML pertama) dan API `/api/c/{token}`.
 */
final class ResolveCard
{
    /** @return array{status:int, data:array, indexable:bool, meta:?array} */
    public function handle(Request $request, string $token): array
    {
        $view = CardView::cached($token);
        if ($view === null) {
            return ['status' => 404, 'data' => ['state' => 'unknown'], 'indexable' => false, 'meta' => null];
        }

        // NTAG 424 DNA (SUN) masuk v1.5; tap dari chip biasa tidak membawa e & m.
        if ($view->tenantId && in_array($view->status, ['active', 'lost', 'paused'], true) && self::countable($request, $view->cardId)) {
            RecordTap::dispatch($view->tenantId, $view->cardId, TapContext::fromRequest($request));
        }

        $inactive = fn (string $reason) => ['state' => 'inactive', 'reason' => $reason, 'brand' => $this->brand($view)];

        $data = match (true) {
            $view->tenantSuspended => $inactive('suspended'),
            $view->status === 'manufactured' => $inactive('manufactured'),
            $view->status === 'unclaimed' && $view->isCompanyPool => $inactive('company_idle'),
            $view->status === 'unclaimed' => ['state' => 'activate', 'token' => $view->token,
                'short_code' => substr($view->shortCode, 0, 4).'-'.substr($view->shortCode, 4)],
            $view->status === 'active' && $view->profile !== null => ['state' => 'profile',
                'profile' => PublicProfile::from($view, event: $this->activeEvent($view))],
            default => $inactive($view->status),
        };

        $p = $data['state'] === 'profile' ? $data['profile'] : null;

        return [
            'status' => 200,
            'data' => $data,
            'indexable' => (bool) ($view->profile['is_indexable'] ?? false),
            // judul & pratinjau tautan (WhatsApp/iMessage) tanpa menunggu JS
            'meta' => $p ? [
                'title' => trim($p['display_name'].' — '.implode(' · ', array_filter([$p['title'], $p['company']])), ' —'),
                'description' => $p['bio'] ? mb_strimwidth($p['bio'], 0, 160, '…') : 'Simpan kontak '.$p['display_name'].' dengan satu ketukan.',
                'image' => $p['avatar_url'],
                'theme' => $p['theme']['mode'] ?? 'dark',
                'lang' => $p['locale'] ?? 'id',
            ] : null,
        ];
    }

    private function brand(CardView $view): ?array
    {
        return $view->tenantName ? [
            'name' => $view->tenantName,
            'logo_url' => ($view->brand['logo_path'] ?? null) ? Storage::disk('public')->url($view->brand['logo_path']) : null,
        ] : null;
    }

    /** "Bertemu di {Event}" bila kartu sedang di event (di-assign / Mode Event) & event mengizinkan tampil di profil (AFD F-10). */
    private function activeEvent(CardView $view): ?string
    {
        $event = EventForCard::at($view->tenantId, $view->cardId, $view->holderUserId, now());

        return ($event?->settings['show_on_profile'] ?? false) ? $event->name : null;
    }

    /**
     * Satu tap per pengunjung (IP + browser) per kartu dalam `tap_dedupe_minutes`: refresh, buka ulang, atau spam
     * tidak menambah statistik & notifikasi. Bukan per IP saja — satu IP dipakai banyak orang (Wi-Fi kantor/event,
     * CGNAT operator seluler), jadi pengunjung berbeda di jaringan yang sama tetap terhitung. Bot & pratinjau link diabaikan.
     */
    private static function countable(Request $request, int $cardId): bool
    {
        if (UserAgent::isBot($request->userAgent())) {
            return false;
        }
        $minutes = (int) config('tapcard.tap_dedupe_minutes');

        return $minutes <= 0
            || Cache::add('tap:seen:'.$cardId.':'.Hasher::ip($request->ip().'|'.$request->userAgent()), 1, now()->addMinutes($minutes));
    }
}
