<?php

namespace App\Actions\Production;

use App\Enums\BatchStatus;
use App\Exceptions\ApiError;
use App\Jobs\FinalizeBatchBundle;
use App\Jobs\GenerateBatchChunk;
use App\Models\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * PR-01 + PR-02 — token, kode pendek, dan kode aktivasi per kartu + bundle CSV vendor, dikerjakan DI LATAR per
 * CHUNK kartu (antrean `render`): bcrypt kode aktivasi ±0,25 dtk/kartu, jadi 1000 kartu dalam satu request
 * melewati batas waktu PHP/Nginx dan seluruhnya ter-rollback. Kode aktivasi hanya ada sebagai teks di bundle cetak;
 * di DB disimpan sebagai hash. Dipanggil lagi setelah gagal / macet → melanjutkan dari kartu yang belum ada.
 */
final class GenerateBatchTokens
{
    public const CHUNK = 50;

    /** Tanpa kemajuan selama ini → dianggap macet (worker mati); boleh dijalankan ulang. */
    public const STALL_MINUTES = 15;

    public function handle(Batch $batch): void
    {
        DB::transaction(function () use ($batch) {
            $batch = Batch::whereKey($batch->id)->lockForUpdate()->first();
            if ($batch->status !== BatchStatus::Draft) {
                throw new ApiError('batch_not_draft', 'Token hanya bisa dibuat untuk batch berstatus draf.');
            }
            if (self::running($batch)) {
                throw new ApiError('batch_generating', 'Token sedang dibuat di latar. Tunggu sampai selesai.');
            }
            $done = (int) $batch->cards()->max('sequence');
            $jobs = [];
            for ($from = $done + 1; $from <= $batch->quantity; $from += self::CHUNK) {
                $jobs[] = new GenerateBatchChunk($batch->id, $from, min($from + self::CHUNK - 1, $batch->quantity));
            }
            $jobs[] = new FinalizeBatchBundle($batch->id);
            $batch->update(['tokens_started_at' => now(), 'tokens_error' => null]);

            // berurutan: bagian CSV tersusun rapi & satu kegagalan menghentikan sisanya (dilanjutkan lewat tombol)
            DB::afterCommit(fn () => Bus::chain($jobs)->onQueue('render')->dispatch());
        });
    }

    /** Sedang berjalan & belum macet. */
    public static function running(Batch $batch): bool
    {
        return $batch->status === BatchStatus::Draft && $batch->tokens_started_at !== null && $batch->tokens_error === null
            && $batch->updated_at?->gt(now()->subMinutes(self::STALL_MINUTES));
    }
}
