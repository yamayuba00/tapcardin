<?php

namespace App\Http\Controllers\Workspace;

use App\Models\Card;
use App\Support\CardPeriod;
use App\Support\TapStats;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;

/**
 * Laporan kartu untuk owner/admin (izin cards.view.all): masa berlaku semua kartu fisik, status digital,
 * dan rekap tap per anggota dalam 30 hari terakhir. Satu sumber data untuk halaman "Laporan kartu".
 */
class CardReportController extends WorkspaceController
{
    /** jendela tap untuk rekap & kolom "Tap 30 hari" */
    private const WINDOW_DAYS = 30;

    /** kartu yang akan jatuh tempo dalam jumlah hari ini dihitung "akan berakhir" */
    private const EXPIRING_DAYS = 30;

    public function index(): JsonResponse
    {
        $this->access()->authorize('cards.view.all');
        $now = now('Asia/Jakarta');
        $from = $now->copy()->subDays(self::WINDOW_DAYS - 1)->startOfDay();

        $cards = Card::query()->whereIn('status', ['active', 'paused', 'lost', 'unclaimed'])
            ->with('holder:id,name,email')
            ->get(['id', 'card_token', 'label', 'short_code', 'form', 'status', 'holder_user_id', 'activated_at', 'valid_until', 'last_tap_at']);
        $taps = TapStats::range($from, null, $cards->pluck('id')->all())['cards'];

        $rows = $cards->map(function (Card $c) use ($now, $taps) {
            $state = self::state($c, $now);
            $days = $c->valid_until ? (int) floor(($c->valid_until->getTimestamp() - $now->getTimestamp()) / 86400) : null;

            return [
                'token' => $c->card_token,
                'label' => $c->label ?: $c->formattedShortCode(),
                'short_code' => $c->formattedShortCode(),
                'form' => $c->form,
                'status' => $c->status->value,
                'holder' => $c->holder ? ['name' => $c->holder->name, 'email' => $c->holder->email] : null,
                'holder_key' => $c->holder_user_id,
                'activated_at' => $c->activated_at?->toIso8601String(),
                'valid_until' => $c->valid_until?->toIso8601String(),
                'grace_until' => $c->valid_until?->copy()->addDays(CardPeriod::graceDays())->toIso8601String(),
                'state' => $state,
                'days_left' => $days,
                'expiring' => $state === 'active' && $days !== null && $days <= self::EXPIRING_DAYS,
                'taps_30' => (int) ($taps[$c->id] ?? 0),
                'last_tap_at' => $c->last_tap_at?->toIso8601String(),
            ];
        })->sortBy(fn (array $r) => $r['valid_until'] ?? '9999')->values();

        $members = $rows->groupBy(fn (array $r) => $r['holder_key'] ?? 0)->map(fn ($g) => [
            'holder' => $g->first()['holder'],
            'cards' => $g->count(),
            'active' => $g->where('state', 'active')->count(),
            'grace' => $g->where('state', 'grace')->count(),
            'expired' => $g->where('state', 'expired')->count(),
            'expiring' => $g->where('expiring', true)->count(),
            'taps_30' => $g->sum('taps_30'),
            'last_tap_at' => $g->pluck('last_tap_at')->filter()->sortDesc()->first(),
        ])->sortByDesc('taps_30')->values();

        return response()->json([
            'data' => $rows->map(fn (array $r) => collect($r)->except('holder_key')->all())->values(),
            'meta' => [
                'members' => $members,
                'totals' => [
                    'cards' => $rows->count(),
                    'digital' => $rows->where('state', 'digital')->count(),
                    'active' => $rows->where('state', 'active')->count(),
                    'grace' => $rows->where('state', 'grace')->count(),
                    'expired' => $rows->where('state', 'expired')->count(),
                    'expiring' => $rows->where('expiring', true)->count(),
                    'taps_30' => $rows->sum('taps_30'),
                ],
                'window_days' => self::WINDOW_DAYS,
                'expiring_days' => self::EXPIRING_DAYS,
                'generated_at' => $now->toIso8601String(),
            ],
        ]);
    }

    /** digital: tanpa masa pakai · none: fisik belum punya masa berlaku · expired / grace / active sesuai CardPeriod */
    private static function state(Card $c, CarbonInterface $now): string
    {
        if ($c->form === Card::DIGITAL) {
            return 'digital';
        }
        if (! $c->valid_until) {
            return 'none';
        }
        if (CardPeriod::expired($c->valid_until, $now)) {
            return 'expired';
        }

        return $c->valid_until->isPast() ? 'grace' : 'active';
    }
}
