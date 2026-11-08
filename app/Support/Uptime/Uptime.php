<?php

namespace App\Support\Uptime;

use App\Models\Monitor;
use Illuminate\Support\Facades\DB;

/** Penyaji data monitor untuk dasbor staf: status, uptime 24 jam / 30 hari, rata-rata respons, sparkline. */
final class Uptime
{
    public static function presentMonitor(Monitor $m): array
    {
        return [
            'id' => $m->public_id, 'name' => $m->name, 'type' => $m->type, 'url' => $m->url, 'method' => $m->method,
            'expected_status' => $m->expected_status, 'keyword' => $m->keyword, 'interval_seconds' => $m->interval_seconds,
            'timeout_seconds' => $m->timeout_seconds, 'retries' => $m->retries, 'ssl_warn_days' => $m->ssl_warn_days,
            'is_public' => $m->is_public, 'public_name' => $m->public_name, 'is_active' => $m->is_active,
            'status' => $m->is_active ? $m->status : 'paused',
            'last_checked_at' => $m->last_checked_at?->toIso8601String(), 'last_change_at' => $m->last_change_at?->toIso8601String(),
            'last_response_ms' => $m->last_response_ms, 'last_http_status' => $m->last_http_status, 'last_error' => $m->last_error,
            'ssl_expires_at' => $m->ssl_expires_at?->toIso8601String(),
        ];
    }

    /** @return list<array> semua monitor + statistik */
    public static function list(): array
    {
        $monitors = Monitor::orderBy('sort')->orderBy('id')->get();
        $stats = collect(DB::select("
            select monitor_id,
              count(*) filter (where checked_at >= now() - interval '24 hours') as n24,
              count(*) filter (where checked_at >= now() - interval '24 hours' and status <> 'down') as up24,
              count(*) as n30, count(*) filter (where status <> 'down') as up30,
              round(avg(response_ms) filter (where checked_at >= now() - interval '24 hours')) as avg24
            from monitor_checks where checked_at >= now() - interval '30 days' group by monitor_id
        "))->keyBy('monitor_id');
        $spark = collect(DB::select("
            select monitor_id, status, response_ms from (
              select monitor_id, status, response_ms, checked_at,
                row_number() over (partition by monitor_id order by checked_at desc) as rn
              from monitor_checks where checked_at >= now() - interval '1 day'
            ) t where rn <= 40 order by monitor_id, checked_at
        "))->groupBy('monitor_id');

        return $monitors->map(function (Monitor $m) use ($stats, $spark) {
            $s = $stats->get($m->id);

            return self::presentMonitor($m) + [
                'uptime_24h' => $s && $s->n24 ? round($s->up24 / $s->n24 * 100, 2) : null,
                'uptime_30d' => $s && $s->n30 ? round($s->up30 / $s->n30 * 100, 2) : null,
                'avg_ms_24h' => $s?->avg24 !== null ? (int) $s->avg24 : null,
                'recent' => ($spark->get($m->id) ?? collect())->map(fn ($c) => ['s' => $c->status, 'ms' => $c->response_ms])->values(),
            ];
        })->values()->all();
    }

    /** Detail satu monitor: cek 24 jam (untuk grafik) & 20 perubahan status terakhir. */
    public static function detail(Monitor $m): array
    {
        return [
            'monitor' => self::presentMonitor($m),
            'checks' => $m->checks()->where('checked_at', '>=', now()->subDay())->orderBy('checked_at')
                ->get(['status', 'response_ms', 'http_status', 'error', 'checked_at'])
                ->map(fn ($c) => ['status' => $c->status, 'ms' => $c->response_ms, 'code' => $c->http_status, 'error' => $c->error, 'at' => $c->checked_at->toIso8601String()]),
            'events' => $m->events()->latest('id')->limit(20)->get()
                ->map(fn ($e) => ['from' => $e->from_status, 'to' => $e->to_status, 'reason' => $e->reason, 'at' => $e->created_at->toIso8601String()]),
        ];
    }
}
