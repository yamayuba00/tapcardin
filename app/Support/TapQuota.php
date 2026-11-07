<?php

namespace App\Support;

use App\Models\Card;
use Illuminate\Support\Facades\DB;

/**
 * Kuota tap kartu digital (config tapcard.digital_card). Kumulatif, tidak di-reset bulanan: habis → profil tidak bisa
 * dibuka sampai pemilik membeli tambahan (invoice `topup`). Pemilik diberi tahu sekali di 90% dan sekali saat habis.
 * `tap_quota` null = tanpa batas (kartu fisik).
 */
final class TapQuota
{
    /** Ambang notifikasi (persen), dari yang tertinggi. */
    private const ALERTS = [100, 90];

    /** Pakai satu tap. false = kuota sudah habis (tap tidak dihitung, profil tidak ditampilkan). */
    public static function consume(int $cardId, ?int $holderUserId, ?int $tenantId): bool
    {
        $row = DB::selectOne('UPDATE cards SET tap_used = tap_used + 1 WHERE id = ? AND (tap_quota IS NULL OR tap_used < tap_quota)
            RETURNING tap_used, tap_quota, card_token', [$cardId]);
        if (! $row) {
            return false;
        }
        if ($row->tap_quota && $holderUserId) {
            self::alert($cardId, $holderUserId, $tenantId, (int) $row->tap_used, (int) $row->tap_quota, $row->card_token);
        }

        return true;
    }

    /** Tanpa memakai kuota (kunjungan ulang yang tidak dihitung): apakah profil masih boleh dibuka? */
    public static function available(int $cardId): bool
    {
        return DB::table('cards')->where('id', $cardId)->where(fn ($q) => $q->whereNull('tap_quota')->orWhereColumn('tap_used', '<', 'tap_quota'))->exists();
    }

    /** @return array{quota:?int, used:int, remaining:?int, exhausted:bool} */
    public static function of(Card $card): array
    {
        $quota = $card->tap_quota === null ? null : (int) $card->tap_quota;
        $used = (int) $card->tap_used;

        return ['quota' => $quota, 'used' => $used, 'remaining' => $quota === null ? null : max(0, $quota - $used),
            'exhausted' => $quota !== null && $used >= $quota];
    }

    /** Tambahan kuota lunas: kuota naik, ambang notifikasi dihitung ulang dari awal. */
    public static function add(int $cardId, int $taps): void
    {
        DB::update('UPDATE cards SET tap_quota = COALESCE(tap_quota, tap_used) + ?, tap_quota_alert = 0 WHERE id = ?', [$taps, $cardId]);
    }

    private static function alert(int $cardId, int $userId, ?int $tenantId, int $used, int $quota, string $token): void
    {
        $pct = intdiv($used * 100, $quota);
        foreach (self::ALERTS as $level) {
            if ($pct < $level) {
                continue;
            }
            // naik ke ambang baru tepat sekali walau banyak tap bersamaan
            if (DB::update('UPDATE cards SET tap_quota_alert = ? WHERE id = ? AND tap_quota_alert < ?', [$level, $cardId, $level])) {
                Notify::user($userId, $tenantId, 'tap_quota', ['card' => $token, 'level' => $level, 'used' => $used, 'quota' => $quota]);
            }

            return;
        }
    }
}
