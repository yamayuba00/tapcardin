<?php

namespace App\Http\Controllers\Workspace;

use App\Enums\CardStatus;
use App\Http\Resources\Present;
use App\Models\Card;
use App\Models\Connection;
use App\Models\Tap;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** U-01 / AN-02 — ringkasan 7/30 hari + aktivitas terbaru realtime. */
class DashboardController extends WorkspaceController
{
    public function summary(): JsonResponse
    {
        $this->access()->authorize('analytics.view.*');
        $tid = $this->tenant()->id;
        $uid = $this->user()->id;
        $scopeAll = $this->access()->has('analytics.view.all');

        $data = Cache::remember("t{$tid}:stats:today:{$uid}", 60, function () use ($uid, $scopeAll) {
            $taps = Tap::query()->when(! $scopeAll, fn ($q) => $q->where('holder_user_id', $uid));
            $since30 = now()->subDays(30);
            $since7 = now()->subDays(7);
            $prev7 = now()->subDays(14);

            $agg = (clone $taps)->where('created_at', '>=', $since30)->selectRaw('
                count(*) filter (where created_at >= ?) as taps_7,
                count(*) filter (where created_at >= ? and created_at < ?) as taps_prev_7,
                count(*) as taps_30,
                count(distinct visitor_hash) as visitors_30,
                count(*) filter (where saved_contact) as saved_30
            ', [$since7, $prev7, $since7])->first();

            $series = (clone $taps)->where('created_at', '>=', now('Asia/Jakarta')->subDays(29)->startOfDay())
                ->selectRaw("(created_at at time zone 'Asia/Jakarta')::date as d, count(*) as n")
                ->groupBy('d')->pluck('n', 'd');
            $days = [];
            for ($i = 29; $i >= 0; $i--) {
                $d = now('Asia/Jakarta')->subDays($i)->toDateString();
                $days[] = ['date' => $d, 'value' => (int) ($series[$d] ?? 0)];
            }

            $connections = Connection::query()->when(! $scopeAll, fn ($q) => $q->where('assignee_user_id', $uid));
            $conn30 = (clone $connections)->where('created_at', '>=', $since30)->count();

            $recentTaps = (clone $taps)->latest('created_at')->limit(8)->get(['card_id', 'city', 'source', 'device', 'created_at']);
            $cardLabels = Card::whereIn('id', $recentTaps->pluck('card_id'))->pluck('label', 'id');
            $recent = $recentTaps->map(fn ($t) => [
                'type' => 'tap', 'at' => $t->created_at->toIso8601String(),
                'title' => 'Profil Anda dibuka', 'meta' => array_values(array_filter([$t->city, $this->sourceLabel($t->source->value), $cardLabels[$t->card_id] ?? null])),
            ])->concat((clone $connections)->latest()->limit(5)->get()->map(fn (Connection $c) => [
                'type' => 'connection', 'at' => $c->created_at->toIso8601String(), 'id' => $c->public_id,
                'title' => $c->name.' terhubung', 'meta' => array_values(array_filter([$c->company])),
            ]))->sortByDesc('at')->values()->take(10)->all();

            $cards = Card::query()->when(! $scopeAll, fn ($q) => $q->where('holder_user_id', $uid))
                ->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status');

            return [
                'kpi' => [
                    'taps_7' => (int) $agg->taps_7,
                    'taps_prev_7' => (int) $agg->taps_prev_7,
                    'taps_30' => (int) $agg->taps_30,
                    'visitors_30' => (int) $agg->visitors_30,
                    'saved_30' => (int) $agg->saved_30,
                    'save_rate' => $agg->taps_30 ? round($agg->saved_30 / $agg->taps_30 * 100) : null,
                    'connections_30' => $conn30,
                ],
                'series' => $days,
                'recent' => $recent,
                'cards' => [
                    'active' => (int) ($cards[CardStatus::Active->value] ?? 0),
                    'total' => (int) $cards->sum(),
                ],
            ];
        });

        $data['cards']['list'] = Card::query()->where('holder_user_id', $uid)
            ->whereIn('status', [CardStatus::Active->value, CardStatus::Paused->value, CardStatus::Lost->value])
            ->with('profile')->orderByRaw("form = 'digital' desc")->orderBy('activated_at')->limit(3)->get()
            ->map(fn (Card $c) => Present::card($c))->all();

        // tombol "Bagikan" cepat: kartu digital aktif, atau kartu aktif pertama
        $data['share_token'] = Card::query()->where('holder_user_id', $uid)->where('status', CardStatus::Active->value)
            ->orderByRaw("form = 'digital' desc")->orderBy('activated_at')->value('card_token');

        return response()->json(['data' => $data]);
    }

    private function sourceLabel(string $source): string
    {
        return ['nfc' => 'NFC', 'qr' => 'QR', 'dynamic_qr' => 'QR aman', 'code' => 'Kode', 'link' => 'Tautan', 'app' => 'Aplikasi'][$source] ?? $source;
    }
}
