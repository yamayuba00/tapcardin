<?php

namespace App\Actions\Production;

use App\Enums\BatchStatus;
use App\Enums\ChipType;
use App\Models\Batch;
use App\Models\Card;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Str;

final class CreateBatch
{
    /** @param  OrderItem|null  $item  pesanan asal: kartu masuk workspace pemesan & bisa diaktifkan pemesan tanpa kode */
    /** @param  bool  $uniform  desain seragam (tanpa QR unik): encode = lolos QC, kartu polos bisa ditulis di tempat (Jual kartu) */
    public function handle(ChipType $chip, int $quantity, ?User $holder = null, ?string $vendor = null, ?Card $replaces = null, ?OrderItem $item = null, bool $uniform = false): Batch
    {
        $batch = Batch::create([
            'code' => 'B'.now()->format('ymd').'-'.Str::upper(Str::random(4)),
            'status' => BatchStatus::Draft,
            'chip_type' => $chip,
            'quantity' => $quantity,
            'vendor' => $vendor,
            // Kartu untuk satu pembeli personal (pilot MVP, sebelum modul pesanan v1)
            'holder_user_id' => $holder?->id,
            'tenant_id' => $item?->order->tenant_id ?? $replaces?->tenant_id ?? $holder?->personal_tenant_id,
            'order_item_id' => $item?->id,
            // kartu pengganti (AFD F-13): aktivasi kartu baru mengambil alih profil & mencabut kartu lama
            'replaces_card_id' => $replaces?->id,
            'uniform' => $uniform,
            'created_by' => auth()->id(),
        ]);
        Audit::platform('batch.created', $batch, null, ['quantity' => $quantity, 'chip' => $chip->value, 'order_item' => $item?->public_id, 'uniform' => $uniform]);

        return $batch;
    }
}
