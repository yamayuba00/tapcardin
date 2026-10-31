<?php

namespace App\Actions\Production;

use App\Actions\Cards\ChangeCardStatus;
use App\Enums\BatchStatus;
use App\Enums\CardStatus;
use App\Exceptions\ApiError;
use App\Models\Batch;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/** Input resi → kartu yang lolos QC menjadi `unclaimed` dan bisa diaktivasi (AFD F-04). */
final class ShipBatch
{
    public function __construct(private readonly ChangeCardStatus $status) {}

    public function handle(Batch $batch, ?string $trackingNumber = null): int
    {
        $cards = $batch->cards()->where('status', CardStatus::Manufactured->value)->whereNotNull('qc_passed_at')->get();
        if ($cards->isEmpty()) {
            throw new ApiError('nothing_to_ship', 'Belum ada kartu yang lolos QC di batch ini.');
        }

        return DB::transaction(function () use ($batch, $cards, $trackingNumber) {
            foreach ($cards as $card) {
                $this->status->handle($card, CardStatus::Unclaimed, $trackingNumber ? "Resi $trackingNumber" : 'Dikirim', platform: true);
            }
            $remaining = $batch->cards()->where('status', CardStatus::Manufactured->value)->count();
            if ($remaining === 0) {
                $batch->update(['status' => BatchStatus::Completed, 'completed_at' => now()]);
            }
            Audit::platform('batch.shipped', $batch, null, ['cards' => $cards->count(), 'tracking' => $trackingNumber]);

            return $cards->count();
        });
    }
}
