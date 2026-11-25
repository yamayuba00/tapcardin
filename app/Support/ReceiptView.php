<?php

namespace App\Support;

use App\Models\Card;
use App\Models\CardSale;
use App\Models\Invoice;
use Illuminate\Support\Carbon;

/** Kuitansi kartu: penjualan langsung (kuitansi dari catatan penjualan) dan perpanjangan masa pakai (dari invoice lunas). */
final class ReceiptView
{
    public static function sale(CardSale $s): array
    {
        $s->loadMissing(['card', 'buyer', 'seller']);
        $card = $s->card;

        return [
            'id' => $s->public_id, 'kind' => 'card_sale', 'number' => self::number('KW', $s->created_at, $s->id),
            'buyer' => ['name' => $s->buyer?->name, 'email' => $s->buyer?->email],
            'card' => ['short_code' => $card?->formattedShortCode(), 'token' => $card?->card_token],
            'period' => ['from' => $card?->activated_at?->toIso8601String(), 'until' => $card?->valid_until?->toIso8601String()],
            'item' => 'Aktivasi kartu penjualan langsung',
            'amount' => (int) $s->amount, 'status' => $s->status,
            'payment_label' => $s->payment_method ? (CardSale::METHODS[$s->payment_method] ?? $s->payment_method) : null,
            'paid_at' => $s->paid_at?->toIso8601String(), 'seller' => $s->seller?->name,
            'invoice_number' => null, 'issued_at' => $s->created_at?->toIso8601String(),
        ];
    }

    public static function renewal(Invoice $i): array
    {
        $snap = $i->billing_snapshot ?? [];
        $card = isset($snap['card_id']) ? Card::withoutGlobalScopes()->find($snap['card_id']) : null;

        return [
            'id' => $i->public_id, 'kind' => 'card_renewal', 'number' => $i->number,
            'buyer' => ['name' => $snap['name'] ?? null, 'email' => $snap['email'] ?? null],
            'card' => ['short_code' => $card?->formattedShortCode(), 'token' => $card?->card_token],
            'period' => ['from' => $snap['period_from'] ?? null, 'until' => $snap['period_until'] ?? null],
            'item' => $snap['label'] ?? 'Perpanjang masa pakai kartu',
            'amount' => (int) $i->total, 'status' => $i->status,
            'payment_label' => $i->payment_method ? (self::methodLabel($i->payment_method)) : null,
            'paid_at' => $i->paid_at?->toIso8601String(), 'seller' => null,
            'invoice_number' => $i->number, 'issued_at' => $i->issued_at?->toIso8601String(),
        ];
    }

    /** Ringkasan untuk daftar riwayat pembelian. */
    public static function summary(array $r): array
    {
        return ['id' => $r['id'], 'kind' => $r['kind'], 'number' => $r['number'], 'item' => $r['item'], 'amount' => $r['amount'],
            'paid_at' => $r['paid_at'], 'card' => $r['card']['short_code']];
    }

    private static function number(string $prefix, $at, int $id): string
    {
        return sprintf('%s/%s/%06d', $prefix, Carbon::parse($at)->timezone('Asia/Jakarta')->format('Y/m'), $id);
    }

    private static function methodLabel(string $method): string
    {
        return ['qris' => 'QRIS', 'manual' => 'Manual / tunai'][$method] ?? $method;
    }
}
