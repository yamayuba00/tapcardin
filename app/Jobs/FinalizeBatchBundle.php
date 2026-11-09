<?php

namespace App\Jobs;

use App\Enums\BatchStatus;
use App\Models\Batch;
use App\Support\Audit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Akhir rangkaian token batch: gabungkan file bagian jadi bundle vendor, lalu batch → "Token dibuat". */
class FinalizeBatchBundle implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $batchId) {}

    public function handle(): void
    {
        $batch = Batch::find($this->batchId);
        if (! $batch || $batch->status !== BatchStatus::Draft) {
            return;
        }
        $count = $batch->cards()->count();
        if ($count !== $batch->quantity) {
            $batch->update(['tokens_error' => "Baru $count dari {$batch->quantity} kartu yang terbentuk. Klik Lanjutkan."]);

            return;
        }

        $disk = Storage::disk('local');
        $dir = "batches/{$batch->code}";
        $parts = collect($disk->files($dir))->filter(fn ($f) => str_ends_with($f, '.csv'))->sort()->values();
        $body = GenerateBatchChunk::csv([['urutan', 'token', 'kode_pendek', 'kode_aktivasi', 'url_nfc', 'url_qr']])
            .$parts->map(fn ($f) => $disk->get($f))->implode('');
        $path = "batches/{$batch->code}.csv";
        $disk->put($path, $body);

        $batch->update(['status' => BatchStatus::TokensGenerated, 'print_bundle_path' => $path, 'tokens_started_at' => null, 'tokens_error' => null]);
        $disk->deleteDirectory($dir);
        Audit::platform('batch.tokens_generated', $batch, null, ['cards' => $count]);
    }

    public function failed(Throwable $e): void
    {
        Batch::whereKey($this->batchId)->update(['tokens_error' => mb_substr('Menyusun bundle: '.$e->getMessage(), 0, 300)]);
    }
}
