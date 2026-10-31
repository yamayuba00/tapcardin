<?php

namespace App\Console\Commands;

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

        // Koneksi dihitung di CTE terpisah lalu di-join: subquery berkorelasi pada kolom yang tidak di-GROUP BY
        // (t.created_at) ditolak PostgreSQL ("subquery uses ungrouped column").
        $n = DB::affectingStatement(<<<'SQL'
            WITH agg AS (
                SELECT t.card_id, (t.created_at AT TIME ZONE 'Asia/Jakarta')::date AS d,
                    max(t.tenant_id) AS tenant_id, max(t.holder_user_id) AS holder_user_id, max(t.team_id) AS team_id,
                    count(*) AS taps, count(DISTINCT t.visitor_hash) AS unique_visitors,
                    count(*) FILTER (WHERE source = 'nfc') AS nfc, count(*) FILTER (WHERE source = 'qr') AS qr,
                    count(*) FILTER (WHERE source = 'dynamic_qr') AS dynamic_qr, count(*) FILTER (WHERE source = 'code') AS code,
                    count(*) FILTER (WHERE source = 'link') AS link, count(*) FILTER (WHERE source = 'app') AS app,
                    count(*) FILTER (WHERE saved_contact) AS vcard_downloads
                FROM taps t
                WHERE t.created_at >= ?
                GROUP BY t.card_id, d
            ), conn AS (
                SELECT c.card_id, (c.created_at AT TIME ZONE 'Asia/Jakarta')::date AS d, count(*) AS n
                FROM connections c
                WHERE c.card_id IS NOT NULL AND c.created_at >= ?
                GROUP BY c.card_id, d
            )
            INSERT INTO daily_card_stats (card_id, date, tenant_id, holder_user_id, team_id, taps, unique_visitors,
                nfc, qr, dynamic_qr, code, link, app, vcard_downloads, connections, link_clicks)
            SELECT a.card_id, a.d, a.tenant_id, a.holder_user_id, a.team_id, a.taps, a.unique_visitors,
                a.nfc, a.qr, a.dynamic_qr, a.code, a.link, a.app, a.vcard_downloads, coalesce(c.n, 0), 0
            FROM agg a LEFT JOIN conn c ON c.card_id = a.card_id AND c.d = a.d
            ON CONFLICT (card_id, date) DO UPDATE SET
                taps = EXCLUDED.taps, unique_visitors = EXCLUDED.unique_visitors, nfc = EXCLUDED.nfc, qr = EXCLUDED.qr,
                dynamic_qr = EXCLUDED.dynamic_qr, code = EXCLUDED.code, link = EXCLUDED.link, app = EXCLUDED.app,
                vcard_downloads = EXCLUDED.vcard_downloads, connections = EXCLUDED.connections
        SQL, [$from->utc(), $from->utc()]);

        $this->info("$n baris statistik diperbarui.");

        return self::SUCCESS;
    }
}
