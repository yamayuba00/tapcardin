<?php

namespace App\Http\Controllers\Platform;

use App\Models\CardSale;
use App\Models\Invoice;
use App\Support\ReceiptView;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Kuitansi kartu untuk staf panel (penjualan langsung dan perpanjangan). */
class ReceiptController extends PlatformController
{
    /** GET /api/platform/receipts/{id} */
    public function show(string $id): JsonResponse
    {
        $this->can('cards.manage');
        if ($s = CardSale::where('public_id', $id)->first()) {
            return $this->ok(ReceiptView::sale($s));
        }
        $i = Invoice::where('public_id', $id)->where('type', 'card_renewal')->where('status', 'paid')->first()
            ?? throw new NotFoundHttpException;

        return $this->ok(ReceiptView::renewal($i));
    }
}
