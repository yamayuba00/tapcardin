<?php

namespace App\Support\Uptime;

use App\Models\Incident;
use App\Models\Monitor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Data halaman status publik (status.{base}): hanya monitor `is_public` dengan nama publiknya — tanpa URL, kode, atau
 * pesan error internal. Bar 90 hari per monitor (WIB), insiden aktif & maintenance terjadwal, riwayat 14 hari.
 * Di-cache 30 detik sebagai array biasa (cache tidak meng-unserialize objek: serializable_classes = false);
 * dibuang saat status berubah / insiden diperbarui (lalu siaran realtime).
 */
final class StatusPage
{
    public const CACHE_KEY = 'status:summary';

    public const DAYS = 90;

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public static function summary(): array
    {
        return Cache::remember(self::CACHE_KEY, 30, fn () => self::build());
    }

    private static function build(): array
    {
        $monitors = Monitor::where('is_public', true)->orderBy('sort')->orderBy('id')->get();
        $from = now('Asia/Jakarta')->subDays(self::DAYS - 1)->startOfDay();
        $daily = $monitors->isEmpty() ? collect() : collect(DB::select("
            select monitor_id, (checked_at at time zone 'Asia/Jakarta')::date as d, count(*) as n,
              count(*) filter (where status = 'down') as down
            from monitor_checks where checked_at >= ? and monitor_id in (".implode(',', $monitors->pluck('id')->all()).')
            group by 1, 2', [$from]))->groupBy('monitor_id');

        $rows = $monitors->map(function (Monitor $m) use ($daily, $from) {
            $byDay = ($daily->get($m->id) ?? collect())->keyBy(fn ($r) => (string) $r->d);
            $days = [];
            [$n, $down] = [0, 0];
            for ($i = 0; $i < self::DAYS; $i++) {
                $d = $from->copy()->addDays($i)->toDateString();
                $r = $byDay->get($d);
                $days[] = $r ? ['date' => $d, 'uptime' => round(($r->n - $r->down) / $r->n * 100, 2), 'down_checks' => (int) $r->down] : ['date' => $d, 'uptime' => null, 'down_checks' => 0];
                [$n, $down] = [$n + ($r->n ?? 0), $down + ($r->down ?? 0)];
            }
            $status = $m->is_active ? $m->status : 'paused';

            return [
                'id' => $m->public_id, 'name' => $m->publicName(),
                'status' => in_array($status, ['pending', 'paused'], true) ? 'unknown' : $status,
                'uptime' => $n ? round(($n - $down) / $n * 100, 2) : null,
                'days' => $days,
            ];
        })->values();

        $open = Incident::with('updates')->whereNull('resolved_at')->orderBy('starts_at')->get();
        $history = Incident::with('updates')->whereNotNull('resolved_at')->where('resolved_at', '>=', now()->subDays(14))->latest('resolved_at')->get();
        $statuses = $rows->pluck('status');
        $activeIncident = $open->first(fn ($i) => $i->kind === 'incident' || $i->status === 'in_progress');

        return [
            'overall' => match (true) {
                $statuses->contains('down') || $open->contains(fn ($i) => $i->kind === 'incident' && $i->impact === 'major') => 'major',
                $statuses->contains('degraded') || $activeIncident !== null => 'minor',
                default => 'operational',
            },
            'monitors' => $rows->all(),
            'incidents' => $open->map(fn ($i) => self::presentIncident($i))->values()->all(),
            'history' => $history->map(fn ($i) => self::presentIncident($i))->values()->all(),
            'updated_at' => now()->toIso8601String(),
        ];
    }

    public static function presentIncident(Incident $i): array
    {
        return [
            'id' => $i->public_id, 'kind' => $i->kind, 'title' => $i->title, 'impact' => $i->impact, 'status' => $i->status,
            'status_label' => Incident::STATUSES[$i->kind][$i->status] ?? $i->status,
            'starts_at' => $i->starts_at->toIso8601String(), 'ends_at' => $i->ends_at?->toIso8601String(), 'resolved_at' => $i->resolved_at?->toIso8601String(),
            'updates' => $i->updates->map(fn ($u) => [
                'status' => $u->status, 'status_label' => Incident::STATUSES[$i->kind][$u->status] ?? $u->status,
                'body' => $u->body, 'at' => $u->created_at->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
