<?php

namespace App\Http\Controllers\Workspace;

use App\Authz\PlanFeatures;
use App\Models\Card;
use App\Models\Connection;
use App\Models\ProfileField;
use App\Support\TapStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** U-08 / AN-01 — per kartu: tap per hari, pengunjung unik, sumber, simpan kontak, klik tautan, kota. */
class AnalyticsController extends WorkspaceController
{
    public function cards(Request $request): JsonResponse
    {
        $this->access()->authorize('analytics.view.*');
        $retention = PlanFeatures::limit($this->tenant(), 'analytics_days') ?? 730;
        $days = min((int) $request->query('days', 30), $retention, 365);
        $days = max($days, 1);
        $from = now('Asia/Jakarta')->subDays($days - 1)->startOfDay();
        $uid = $this->user()->id;
        $all = $this->access()->has('analytics.view.all');

        $cardIds = $request->query('card') ? Card::where('card_token', $request->query('card'))->pluck('id')->all() : null;
        $stats = TapStats::range($from, $all ? null : $uid, $cardIds);

        $byDay = $stats['days'];
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = now('Asia/Jakarta')->subDays($i)->toDateString();
            $series[] = ['date' => $d, 'value' => $byDay[$d]['taps'] ?? 0, 'unique' => $byDay[$d]['unique'] ?? 0];
        }

        $cards = Card::query()->when(! $all, fn ($q) => $q->where('holder_user_id', $uid))
            ->whereIn('status', ['active', 'paused', 'lost'])->get(['id', 'card_token', 'label', 'short_code', 'profile_id']);
        $perCard = $stats['cards'];
        $clicks = ProfileField::whereIn('profile_id', $cards->pluck('profile_id')->filter())
            ->where('click_count', '>', 0)->orderByDesc('click_count')->limit(8)->get(['label', 'type', 'click_count']);

        return response()->json(['data' => [
            'days' => $days,
            'retention_days' => PlanFeatures::limit($this->tenant(), 'analytics_days'),
            'totals' => [
                'taps' => array_sum(array_column($byDay, 'taps')),
                'visitors' => array_sum(array_column($byDay, 'unique')),
                'saved' => array_sum(array_column($byDay, 'saved')),
                'connections' => Connection::where('created_at', '>=', $from)->when(! $all, fn ($q) => $q->where('assignee_user_id', $uid))->count(),
            ],
            'series' => $series,
            'sources' => TapStats::top($stats['sources']),
            'devices' => TapStats::top($stats['devices']),
            'cities' => TapStats::top($stats['cities']),
            'cards' => $cards->map(fn ($c) => ['token' => $c->card_token, 'label' => $c->label ?: $c->formattedShortCode(), 'taps' => (int) ($perCard[$c->id] ?? 0)])->sortByDesc('taps')->values(),
            'links' => $clicks->map(fn ($f) => ['label' => $f->label, 'type' => $f->type, 'clicks' => $f->click_count]),
        ]]);
    }
}
