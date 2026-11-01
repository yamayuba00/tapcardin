<?php

namespace App\Actions\Production;

use App\Actions\Cards\ChangeCardStatus;
use App\Enums\BatchStatus;
use App\Enums\CardStatus;
use App\Exceptions\ApiError;
use App\Models\Card;
use App\Support\Audit;

/** PR-04 — NFC & QR harus menunjuk token yang sama dengan kartu yang diperiksa. */
final class QcCard
{
    public function __construct(private readonly ChangeCardStatus $status) {}

    public static function tokenFromUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        return preg_match('~/c/([0-9A-Za-z]{10})(?:[/?#]|$)~', $url, $m) ? $m[1] : null;
    }

    /**
     * @param  bool  $withoutQr  kartu polos tanpa QR tercetak: hanya URL di chip NFC yang diperiksa
     * @return array{passed: bool, reason: ?string}
     */
    public function handle(Card $card, ?string $nfcUrl, ?string $qrUrl, bool $failHard = false, bool $withoutQr = false): array
    {
        if ($card->status !== CardStatus::Manufactured) {
            throw new ApiError('card_not_in_production', 'Kartu ini sudah keluar dari produksi.');
        }
        $nfc = self::tokenFromUrl($nfcUrl);
        $qr = self::tokenFromUrl($qrUrl);

        $reason = match (true) {
            $card->encoded_at === null => 'Kartu belum di-encode.',
            $nfc === null => 'URL NFC tidak terbaca.',
            ! $withoutQr && $qr === null => 'URL QR tidak terbaca.',
            ! $withoutQr && $nfc !== $qr => 'NFC dan QR menunjuk kartu berbeda.',
            $nfc !== $card->card_token => 'Kartu tidak sesuai urutan yang diperiksa.',
            default => null,
        };

        if ($reason === null) {
            $card->forceFill(['qc_passed_at' => now()])->save();
            if ($card->batch->status === BatchStatus::Encoding) {
                $card->batch->update(['status' => BatchStatus::Qc]);
            }
            Audit::platform('card.qc_passed', $card, null, $withoutQr ? ['without_qr' => true] : null);

            return ['passed' => true, 'reason' => null];
        }

        if ($failHard) {
            // AFD F-04: gagal QC → kartu revoked, cetak ulang
            $this->status->handle($card, CardStatus::Revoked, 'Gagal QC: '.$reason, platform: true);
        }
        Audit::platform('card.qc_failed', $card, null, ['reason' => $reason]);

        return ['passed' => false, 'reason' => $reason];
    }
}
