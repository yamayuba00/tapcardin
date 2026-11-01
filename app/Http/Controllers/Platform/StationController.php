<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Production\EncodeCard;
use App\Actions\Production\QcCard;
use App\Enums\CardStatus;
use App\Models\Batch;
use App\Models\Card;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** M-03 stasiun encode Web NFC & M-04 QC (AFD F-04, TRD §7.5). */
class StationController extends PlatformController
{
    /** Batch yang siap encode/QC (pilihan di layar stasiun). */
    public function batches(): JsonResponse
    {
        $this->can('production.manage');

        return $this->ok(Batch::whereIn('status', ['tokens_generated', 'sent_to_print', 'printed', 'encoding', 'qc'])
            ->latest()->get()
            ->map(fn ($b) => ['id' => $b->public_id, 'code' => $b->code, 'quantity' => $b->quantity, 'chip' => $b->chip_type->value, 'status' => $b->status->label()]));
    }

    /** Kartu berikutnya dalam batch yang belum di-encode / belum QC. */
    public function next(Request $request, Batch $batch): JsonResponse
    {
        $this->can('production.manage');
        $mode = $request->query('mode', 'encode');
        $q = $batch->cards()->where('status', CardStatus::Manufactured->value)->orderBy('sequence');
        $q = $mode === 'qc' ? $q->whereNotNull('encoded_at')->whereNull('qc_passed_at') : $q->whereNull('encoded_at');
        if ($after = (int) $request->query('after')) {
            $q->where('sequence', '>', $after);
        }
        $card = $q->first();
        $remaining = (clone $q)->reorder()->count();

        return response()->json(['data' => $card ? [
            'token' => $card->card_token,
            'sequence' => $card->sequence,
            'short_code' => $card->formattedShortCode(),
            'url' => $card->resolverUrl(),
            'qr_url' => $card->qrUrl(),
            'holder' => $batch->holder?->name,
            'remaining' => $remaining,
            'total' => $batch->quantity,
        ] : null]);
    }

    public function encoded(Request $request, string $token, EncodeCard $encode): JsonResponse
    {
        $this->can('production.manage');
        $data = $request->validate(['uid' => ['required', 'string', 'max:40']]);
        $card = $encode->handle(Card::acrossTenants()->where('card_token', $token)->firstOrFail(), $data['uid']);

        return response()->json(['data' => ['token' => $card->card_token, 'encoded_at' => $card->encoded_at->toIso8601String()]]);
    }

    public function qcResult(Request $request, string $token, QcCard $qc): JsonResponse
    {
        $this->can('production.manage');
        $data = $request->validate([
            'nfc_url' => ['nullable', 'string', 'max:300'],
            'qr_url' => ['nullable', 'string', 'max:300'],
            'revoke_on_fail' => ['boolean'],
            'without_qr' => ['boolean'],
        ]);
        $card = Card::acrossTenants()->where('card_token', $token)->firstOrFail();

        return response()->json(['data' => $qc->handle(
            $card, $data['nfc_url'] ?? null, $data['qr_url'] ?? null, $data['revoke_on_fail'] ?? false, $data['without_qr'] ?? false,
        )]);
    }
}
