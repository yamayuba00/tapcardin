<?php

namespace App\Http\Controllers\Workspace;

use App\Models\CardSale;
use App\Models\Invoice;
use App\Support\ReceiptView;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Kuitansi kartu milik pembeli sendiri: pembelian langsung dan perpanjangan masa pakai. */
class ReceiptController extends WorkspaceController
{
    /** GET /api/receipts — riwayat pembelian kartu pengguna ini. */
    public function index(): JsonResponse
    {
        $uid = $this->user()->id;
        $sales = CardSale::where('buyer_user_id', $uid)->with(['card', 'buyer', 'seller'])->latest('id')->limit(50)->get()
            ->map(fn (CardSale $s) => ReceiptView::summary(ReceiptView::sale($s)));
        $renewals = Invoice::where('user_id', $uid)->where('type', 'card_renewal')->where('status', 'paid')->latest('id')->limit(50)->get()
            ->map(fn (Invoice $i) => ReceiptView::summary(ReceiptView::renewal($i)));

        return response()->json(['data' => $sales->merge($renewals)->sortByDesc('paid_at')->values()->all()]);
    }

    /** GET /api/receipts/{id} */
    public function show(string $id): JsonResponse
    {
        $uid = $this->user()->id;
        if ($s = CardSale::where('public_id', $id)->where('buyer_user_id', $uid)->first()) {
            return response()->json(['data' => ReceiptView::sale($s)]);
        }
        $i = Invoice::where('public_id', $id)->where('user_id', $uid)->where('type', 'card_renewal')->where('status', 'paid')->first()
            ?? throw new NotFoundHttpException;

        return response()->json(['data' => ReceiptView::renewal($i)]);
    }
}
