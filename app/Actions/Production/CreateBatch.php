<?php

namespace App\Actions\Production;

use App\Enums\BatchStatus;
use App\Enums\ChipType;
use App\Models\Batch;
use App\Models\Card;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Str;

final class CreateBatch
{
    public function handle(ChipType $chip, int $quantity, ?User $holder = null, ?string $vendor = null, ?Card $replaces = null): Batch
    {
        $batch = Batch::create([
            'code' => 'B'.now()->format('ymd').'-'.Str::upper(Str::random(4)),
            'status' => BatchStatus::Draft,
            'chip_type' => $chip,
            'quantity' => $quantity,
            'vendor' => $vendor,
            // Kartu untuk satu pembeli personal (pilot MVP, sebelum modul pesanan v1)
            'holder_user_id' => $holder?->id,
            'tenant_id' => $replaces?->tenant_id ?? $holder?->personal_tenant_id,
            // kartu pengganti (AFD F-13): aktivasi kartu baru mengambil alih profil & mencabut kartu lama
            'replaces_card_id' => $replaces?->id,
            'created_by' => auth()->id(),
        ]);
        Audit::platform('batch.created', $batch, null, ['quantity' => $quantity, 'chip' => $chip->value]);

        return $batch;
    }
}
