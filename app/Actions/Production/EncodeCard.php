<?php

namespace App\Actions\Production;

use App\Enums\BatchStatus;
use App\Enums\CardStatus;
use App\Exceptions\ApiError;
use App\Models\Card;
use App\Support\Audit;
use App\Support\Hasher;
use Illuminate\Support\Facades\Crypt;

/** PR-03 — stasiun encode melapor setelah tulis → verifikasi → kunci berhasil (TRD §7.5). */
final class EncodeCard
{
    public function handle(Card $card, string $uid): Card
    {
        if ($card->status !== CardStatus::Manufactured) {
            throw new ApiError('card_not_in_production', 'Kartu ini sudah keluar dari produksi.');
        }
        $uid = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $uid));
        if (strlen($uid) < 8) {
            throw new ApiError('uid_invalid', 'UID chip tidak terbaca.');
        }
        $hash = Hasher::uid($uid);
        $owner = Card::acrossTenants()->where('chip_uid_hash', $hash)->where('id', '!=', $card->id)->first();
        if ($owner) {
            throw new ApiError('uid_duplicate', "Chip ini sudah tercatat untuk kartu #{$owner->sequence}.");
        }

        $card->forceFill([
            'chip_uid_hash' => $hash,
            'chip_uid_enc' => Crypt::encryptString($uid),
            'encoded_at' => now(),
        ])->save();

        $batch = $card->batch;
        if (in_array($batch->status, [BatchStatus::TokensGenerated, BatchStatus::SentToPrint, BatchStatus::Printed], true)) {
            $batch->update(['status' => BatchStatus::Encoding]);
        }
        Audit::platform('card.encoded', $card);

        return $card;
    }
}
