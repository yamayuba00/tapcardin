<?php

namespace App\Http\Controllers\Workspace;

use App\Authz\PlanFeatures;
use App\Models\Card;
use App\Models\Connection;
use App\Models\ProfileField;
use App\Models\Tap;
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

        $taps = Tap::where('created_at', '>=', $from)->when(! $all, fn ($q) => $q->where('holder_user_id', $uid))
            ->when($request->query('card'), fn ($q, $token) => $q->whereIn('card_id', Card::where('card_token', $token)->select('id')));

        $totals = (clone $taps)->selectRaw('count(*) as taps, count(distinct visitor_hash) as visitors, count(*) filter (where saved_contact) as saved')->first();
        $byDay = (clone $taps)->selectRaw("(created_at at time zone 'Asia/Jakarta')::date as d, count(*) as n, count(distinct visitor_hash) as u")
            ->groupBy('d')->get()->keyBy('d');
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = now('Asia/Jakarta')->subDays($i)->toDateString();
            $series[] = ['date' => $d, 'value' => (int) ($byDay[$d]->n ?? 0), 'unique' => (int) ($byDay[$d]->u ?? 0)];
        }
        $group = fn (string $col) => (clone $taps)->selectRaw("coalesce($col, '—') as k, count(*) as n")
            ->groupBy('k')->orderByDesc('n')->limit(8)->pluck('n', 'k');

        $cards = Card::query()->when(! $all, fn ($q) => $q->where('holder_user_id', $uid))
            ->whereIn('status', ['active', 'paused', 'lost'])->get(['id', 'card_token', 'label', 'short_code', 'profile_id']);
        $perCard = (clone $taps)->selectRaw('card_id, count(*) as n')->groupBy('card_id')->pluck('n', 'card_id');
        $clicks = ProfileField::whereIn('profile_id', $cards->pluck('profile_id')->filter())
            ->where('click_count', '>', 0)->orderByDesc('click_count')->limit(8)->get(['label', 'type', 'click_count']);

        return response()->json(['data' => [
            'days' => $days,
            'retention_days' => PlanFeatures::limit($this->tenant(), 'analytics_days'),
            'totals' => [
                'taps' => (int) $totals->taps,
                'visitors' => (int) $totals->visitors,
                'saved' => (int) $totals->saved,
                'connections' => Connection::where('created_at', '>=', $from)->when(! $all, fn ($q) => $q->where('assignee_user_id', $uid))->count(),
            ],
            'series' => $series,
            'sources' => $group('source'),
            'devices' => $group('device'),
            'cities' => $group('city'),
            'cards' => $cards->map(fn ($c) => ['token' => $c->card_token, 'label' => $c->label ?: $c->formattedShortCode(), 'taps' => (int) ($perCard[$c->id] ?? 0)])->sortByDesc('taps')->values(),
            'links' => $clicks->map(fn ($f) => ['label' => $f->label, 'type' => $f->type, 'clicks' => $f->click_count]),
        ]]);
    }
}
