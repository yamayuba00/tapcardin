<?php

namespace App\Console\Commands;

use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** BSD §7.5 — agregasi `taps` → `daily_card_stats` (hari ini & kemarin, WIB) agar dashboard tidak memindai tabel mentah. */
class AggregateStats extends Command
{
    protected $signature = 'tapcard:aggregate-stats {--days=2}';

    protected $description = 'Agregasi statistik kartu harian';

    public function handle(): int
    {
        $from = now('Asia/Jakarta')->subDays((int) $this->option('days') - 1)->startOfDay();
        $end = now('Asia/Jakarta')->addDay()->startOfDay();

        // Rentang panjang (isi ulang setelah migrasi) dipecah per 31 hari agar setiap query tetap kecil.
        $n = 0;
        while ($from->lt($end)) {
            $to = $from->copy()->addDays(31)->min($end);
            $n += $this->aggregate($from->copy()->utc(), $to->copy()->utc());
            $from = $to;
        }

        $this->info("$n baris statistik diperbarui.");

        return self::SUCCESS;
    }

    private function aggregate(CarbonInterface $from, CarbonInterface $to): int
    {
        // Koneksi dihitung di CTE terpisah lalu di-join: subquery berkorelasi pada kolom yang tidak di-GROUP BY
        // (t.created_at) ditolak PostgreSQL ("subquery uses ungrouped column").
        // Perangkat & kota disimpan sebagai jsonb {nilai: jumlah} agar halaman analitik tidak perlu memindai `taps`.
        return DB::affectingStatement(<<<'SQL'
            WITH base AS (
                SELECT t.card_id, (t.created_at AT TIME ZONE 'Asia/Jakarta')::date AS d, t.tenant_id, t.holder_user_id,
                    t.team_id, t.visitor_hash, t.source, t.saved_contact, t.device, coalesce(t.city, '—') AS city
                FROM taps t
                WHERE t.created_at >= ? AND t.created_at < ?
            ), agg AS (
                SELECT card_id, d,
                    max(tenant_id) AS tenant_id, max(holder_user_id) AS holder_user_id, max(team_id) AS team_id,
                    count(*) AS taps, count(DISTINCT visitor_hash) AS unique_visitors,
                    count(*) FILTER (WHERE source = 'nfc') AS nfc, count(*) FILTER (WHERE source = 'qr') AS qr,
                    count(*) FILTER (WHERE source = 'dynamic_qr') AS dynamic_qr, count(*) FILTER (WHERE source = 'code') AS code,
                    count(*) FILTER (WHERE source = 'link') AS link, count(*) FILTER (WHERE source = 'app') AS app,
                    count(*) FILTER (WHERE saved_contact) AS vcard_downloads
                FROM base
                GROUP BY card_id, d
            ), dev AS (
                SELECT card_id, d, jsonb_object_agg(device, n) AS j
                FROM (SELECT card_id, d, device, count(*) AS n FROM base GROUP BY card_id, d, device) x
                GROUP BY card_id, d
            ), cty AS (
                SELECT card_id, d, jsonb_object_agg(city, n) AS j
                FROM (SELECT card_id, d, city, count(*) AS n FROM base GROUP BY card_id, d, city) x
                GROUP BY card_id, d
            ), conn AS (
                SELECT c.card_id, (c.created_at AT TIME ZONE 'Asia/Jakarta')::date AS d, count(*) AS n
                FROM connections c
                WHERE c.card_id IS NOT NULL AND c.created_at >= ? AND c.created_at < ?
                GROUP BY c.card_id, d
            )
            INSERT INTO daily_card_stats (card_id, date, tenant_id, holder_user_id, team_id, taps, unique_visitors,
                nfc, qr, dynamic_qr, code, link, app, vcard_downloads, connections, link_clicks, devices, cities)
            SELECT a.card_id, a.d, a.tenant_id, a.holder_user_id, a.team_id, a.taps, a.unique_visitors,
                a.nfc, a.qr, a.dynamic_qr, a.code, a.link, a.app, a.vcard_downloads, coalesce(c.n, 0), 0,
                coalesce(v.j, '{}'), coalesce(y.j, '{}')
            FROM agg a
            LEFT JOIN conn c ON c.card_id = a.card_id AND c.d = a.d
            LEFT JOIN dev v ON v.card_id = a.card_id AND v.d = a.d
            LEFT JOIN cty y ON y.card_id = a.card_id AND y.d = a.d
            ON CONFLICT (card_id, date) DO UPDATE SET
                taps = EXCLUDED.taps, unique_visitors = EXCLUDED.unique_visitors, nfc = EXCLUDED.nfc, qr = EXCLUDED.qr,
                dynamic_qr = EXCLUDED.dynamic_qr, code = EXCLUDED.code, link = EXCLUDED.link, app = EXCLUDED.app,
                vcard_downloads = EXCLUDED.vcard_downloads, connections = EXCLUDED.connections,
                devices = EXCLUDED.devices, cities = EXCLUDED.cities
        SQL, [$from, $to, $from, $to]);
    }
}
