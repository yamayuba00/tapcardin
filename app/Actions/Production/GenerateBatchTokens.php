<?php

namespace App\Actions\Production;

use App\Enums\BatchStatus;
use App\Enums\CardStatus;
use App\Exceptions\ApiError;
use App\Models\Batch;
use App\Models\Card;
use App\Support\Audit;
use App\Support\Base62;
use App\Support\Crockford;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * PR-01 + PR-02 — buat token, kode pendek, dan kode aktivasi per kartu, lalu tulis bundle CSV vendor.
 * Kode aktivasi hanya ada dalam bentuk teks di bundle cetak; di DB disimpan sebagai hash.
 */
final class GenerateBatchTokens
{
    public function handle(Batch $batch): string
    {
        if ($batch->status !== BatchStatus::Draft) {
            throw new ApiError('batch_not_draft', 'Token hanya bisa dibuat untuk batch berstatus draf.');
        }

        $rows = DB::transaction(function () use ($batch) {
            $rows = [];
            for ($seq = 1; $seq <= $batch->quantity; $seq++) {
                $activation = Crockford::random(8);
                $card = Card::create([
                    'card_token' => $this->unique('card_token', fn () => Base62::random(10)),
                    'short_code' => $this->unique('short_code', fn () => Crockford::random(8)),
                    'status' => CardStatus::Manufactured,
                    'chip_type' => $batch->chip_type,
                    'activation_code_hash' => Hash::make($activation),
                    'tenant_id' => $batch->tenant_id,
                    'assigned_user_id' => $batch->holder_user_id,
                    'replaces_card_id' => $batch->replaces_card_id,
                    'batch_id' => $batch->id,
                    'sequence' => $seq,
                ]);
                $rows[] = [$seq, $card->card_token, $card->formattedShortCode(), $activation, $card->resolverUrl(), $card->qrUrl()];
            }
            $batch->update(['status' => BatchStatus::TokensGenerated]);

            return $rows;
        });

        $path = "batches/{$batch->code}.csv";
        $fh = fopen('php://temp', 'w+');
        fputcsv($fh, ['urutan', 'token', 'kode_pendek', 'kode_aktivasi', 'url_nfc', 'url_qr'], escape: '');
        foreach ($rows as $row) {
            fputcsv($fh, $row, escape: '');
        }
        rewind($fh);
        Storage::disk('local')->put($path, stream_get_contents($fh));
        fclose($fh);

        $batch->update(['print_bundle_path' => $path]);
        Audit::platform('batch.tokens_generated', $batch, null, ['cards' => count($rows)]);

        return $path;
    }

    private function unique(string $column, callable $make): string
    {
        do {
            $value = $make();
        } while (Card::acrossTenants()->where($column, $value)->exists());

        return $value;
    }
}
