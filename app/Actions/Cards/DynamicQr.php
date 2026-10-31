<?php

namespace App\Actions\Cards;

use App\Enums\CardStatus;
use App\Models\Card;
use App\Support\Qr\QrEncoder;
use App\Support\Qr\SvgRenderer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AFD F-11 — QR aman di layar pemilik: token acak sekali pakai, berlaku 60 detik, disimpan hanya
 * sebagai SHA-256. Scan → /q/{token} → ditandai terpakai → redirect ke /c/{card}?src=dynamic_qr.
 * Berguna saat kartu fisik tertinggal atau QR cetak di kartu tidak ingin dipotret orang lain.
 */
final class DynamicQr
{
    public const TTL = 60;

    /** @return array{url:string, expires_at:string, ttl:int, qr:array{modules:int, path:string}} */
    public static function issue(Card $card): array
    {
        $token = Str::random(24);
        $expires = now()->addSeconds(self::TTL);
        DB::table('dynamic_qr_tokens')->insert([
            'card_id' => $card->id, 'token_hash' => hash('sha256', $token), 'expires_at' => $expires, 'created_at' => now(),
        ]);
        // token lama kartu ini yang sudah lewat tidak berguna lagi
        DB::table('dynamic_qr_tokens')->where('card_id', $card->id)->where('expires_at', '<', now()->subMinutes(10))->delete();

        $url = config('tapcard.resolver_url').'/q/'.$token;
        $qr = QrEncoder::encode($url);

        return ['url' => $url, 'expires_at' => $expires->toIso8601String(), 'ttl' => self::TTL, 'qr' => ['modules' => $qr->size, 'path' => SvgRenderer::path($qr)]];
    }

    /** Tukar token dengan URL kartu; null bila tidak dikenal, kedaluwarsa, atau sudah dipakai. */
    public static function redeem(string $token): ?string
    {
        $row = DB::table('dynamic_qr_tokens')->where('token_hash', hash('sha256', $token))->first();
        if (! $row || $row->used_at || now()->greaterThan($row->expires_at)) {
            return null;
        }
        // klaim atomik: dua scan bersamaan → hanya satu yang lolos
        $claimed = DB::table('dynamic_qr_tokens')->where('id', $row->id)->whereNull('used_at')->update(['used_at' => now()]);
        $card = Card::acrossTenants()->find($row->card_id);
        if (! $claimed || ! $card || $card->status !== CardStatus::Active) {
            return null;
        }

        return $card->resolverUrl().'?src=dynamic_qr';
    }
}
