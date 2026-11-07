<?php

namespace App\Support;

use App\Models\DailyCardStat;
use App\Models\Tap;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Statistik tap untuk dashboard & analitik tanpa memindai tabel `taps` mentah untuk rentang panjang:
 * hari-hari lama dibaca dari `daily_card_stats` (diringkas tiap 15 menit), kemarin & hari ini dari `taps`
 * (paling 2 hari data, selalu cepat & segar). Diuji 7 juta tap: 1 tahun perusahaan besar dari ±8 dtk → milidetik.
 *
 * Pengunjung unik = jumlah pengunjung unik harian per kartu (pengunjung yang sama di hari lain dihitung lagi).
 */
final class TapStats
{
    private const SOURCES = ['nfc', 'qr', 'dynamic_qr', 'code', 'link', 'app'];

    /**
     * @param  int|null  $holderId  hanya kartu yang dipegang pengguna ini; null = semua kartu workspace
     * @param  int[]|null  $cardIds  batasi ke kartu tertentu
     * @return array{days: array<string, array{taps:int, unique:int, saved:int}>, sources: array<string,int>,
     *               devices: array<string,int>, cities: array<string,int>, cards: array<int,int>}
     */
    public static function range(CarbonInterface $from, ?int $holderId = null, ?array $cardIds = null): array
    {
        $from = $from->copy()->timezone('Asia/Jakarta')->startOfDay();
        $live = now('Asia/Jakarta')->subDay()->startOfDay();
        $scope = fn (Builder $q) => $q->when($holderId, fn ($q) => $q->where('holder_user_id', $holderId))
            ->when($cardIds !== null, fn ($q) => $q->whereIn('card_id', $cardIds));
        $out = ['days' => [], 'sources' => [], 'devices' => [], 'cities' => [], 'cards' => []];
        $add = function (string $key, $k, $n) use (&$out) {
            $out[$key][$k] = ($out[$key][$k] ?? 0) + (int) $n;
        };

        if ($from->lt($live)) {
            $rollup = DailyCardStat::query()->where('date', '>=', $from->toDateString())->where('date', '<', $live->toDateString())->tap($scope);
            $sums = collect(self::SOURCES)->map(fn ($s) => "sum($s) as $s")->implode(', ');
            foreach ((clone $rollup)->selectRaw("date::text as d, sum(taps) as taps, sum(unique_visitors) as u, sum(vcard_downloads) as s, $sums")->groupBy('date')->get() as $r) {
                $out['days'][$r->d] = ['taps' => (int) $r->taps, 'unique' => (int) $r->u, 'saved' => (int) $r->s];
                foreach (self::SOURCES as $s) {
                    $r->$s && $add('sources', $s, $r->$s);
                }
            }
            foreach ((clone $rollup)->selectRaw('card_id, sum(taps) as n')->groupBy('card_id')->pluck('n', 'card_id') as $k => $n) {
                $add('cards', $k, $n);
            }
            foreach (['devices', 'cities'] as $col) {
                // fungsi di FROM boleh merujuk tabel sebelumnya (LATERAL implisit di PostgreSQL)
                $rows = (clone $rollup)->crossJoin(DB::raw("jsonb_each_text(daily_card_stats.$col) as e(k, v)"))
                    ->selectRaw('e.k as k, sum(e.v::int) as n')->groupBy('e.k')->pluck('n', 'k');
                foreach ($rows as $k => $n) {
                    $add($col, $k, $n);
                }
            }
        }

        $raw = Tap::query()->where('created_at', '>=', $from->max($live)->copy()->utc())->tap($scope);
        foreach ((clone $raw)->selectRaw("(created_at at time zone 'Asia/Jakarta')::date::text as d, count(*) as taps,
                count(distinct (card_id, visitor_hash)) as u, count(*) filter (where saved_contact) as s")->groupBy('d')->get() as $r) {
            $out['days'][$r->d] = ['taps' => (int) $r->taps, 'unique' => (int) $r->u, 'saved' => (int) $r->s];
        }
        foreach (['sources' => 'source', 'devices' => 'device', 'cities' => "coalesce(city, '—')", 'cards' => 'card_id'] as $key => $expr) {
            foreach ((clone $raw)->selectRaw("$expr as k, count(*) as n")->groupBy('k')->pluck('n', 'k') as $k => $n) {
                $add($key, $k, $n);
            }
        }

        return $out;
    }

    /** @param  array<string,int>  $counts */
    public static function top(array $counts, int $limit = 8): array
    {
        arsort($counts);

        return array_slice($counts, 0, $limit, true);
    }
}
