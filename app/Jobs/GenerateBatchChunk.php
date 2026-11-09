<?php

namespace App\Jobs;

use App\Enums\BatchStatus;
use App\Enums\CardStatus;
use App\Models\Batch;
use App\Models\Card;
use App\Support\Base62;
use App\Support\Crockford;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Satu potongan token batch (urutan `from`..`to`, ≤ 50 kartu) dalam transaksinya sendiri. Baris CSV (dengan kode
 * aktivasi teks — satu-satunya tempat kode itu ada) ditulis ke file bagian di disk privat SEBELUM commit: bila commit
 * gagal, percobaan ulang menimpa file yang sama; bila potongan sudah lengkap, dilewati. Dirangkai berurutan
 * (GenerateBatchTokens) dan diakhiri FinalizeBatchBundle.
 */
class GenerateBatchChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public array $backoff = [10, 60];

    public function __construct(public int $batchId, public int $from, public int $to) {}

    public static function partPath(Batch $batch, int $from): string
    {
        return sprintf('batches/%s/part-%06d.csv', $batch->code, $from);
    }

    public function handle(): void
    {
        $batch = Batch::find($this->batchId);
        if (! $batch || $batch->status !== BatchStatus::Draft) {
            return; // dibatalkan / sudah selesai
        }
        $have = $batch->cards()->whereBetween('sequence', [$this->from, $this->to])->count();
        if ($have === $this->to - $this->from + 1) {
            return; // potongan ini sudah dibuat pada percobaan sebelumnya
        }

        DB::transaction(function () use ($batch) {
            $rows = [];
            for ($seq = $this->from; $seq <= $this->to; $seq++) {
                $activation = Crockford::random(8);
                $card = Card::create([
                    'card_token' => self::unique('card_token', fn () => Base62::random(10)),
                    'short_code' => self::unique('short_code', fn () => Crockford::random(8)),
                    'status' => CardStatus::Manufactured,
                    'chip_type' => $batch->chip_type,
                    'activation_code_hash' => Hash::make($activation),
                    'tenant_id' => $batch->tenant_id,
                    'assigned_user_id' => $batch->holder_user_id,
                    'replaces_card_id' => $batch->replaces_card_id,
                    'order_item_id' => $batch->order_item_id,
                    'batch_id' => $batch->id,
                    'sequence' => $seq,
                ]);
                $rows[] = [$seq, $card->card_token, $card->formattedShortCode(), $activation, $card->resolverUrl(), $card->qrUrl()];
            }
            Storage::disk('local')->put(self::partPath($batch, $this->from), self::csv($rows));
            $batch->touch(); // tanda masih berjalan (deteksi macet: GenerateBatchTokens::running)
        });
    }

    /** Gagal setelah semua percobaan → tampil di halaman batch; tombol "Lanjutkan" meneruskan dari potongan ini. */
    public function failed(Throwable $e): void
    {
        Batch::whereKey($this->batchId)->update(['tokens_error' => mb_substr("Kartu {$this->from}–{$this->to}: ".$e->getMessage(), 0, 300)]);
    }

    /** @param  list<array>  $rows */
    public static function csv(array $rows): string
    {
        $fh = fopen('php://temp', 'w+');
        foreach ($rows as $row) {
            fputcsv($fh, $row, escape: '');
        }
        rewind($fh);
        $out = stream_get_contents($fh);
        fclose($fh);

        return $out;
    }

    private static function unique(string $column, callable $make): string
    {
        do {
            $value = $make();
        } while (Card::acrossTenants()->where($column, $value)->exists());

        return $value;
    }
}
