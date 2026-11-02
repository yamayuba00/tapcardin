<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Cards\FieldActivate;
use App\Enums\CardStatus;
use App\Exceptions\ApiError;
use App\Models\Card;
use App\Models\CardSale;
use App\Support\Audit;
use App\Support\Crockford;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Aktivasi di tempat (penjualan langsung): staf menempelkan / mengetik kartu, mengisi data pelanggan & kode aktivasi,
 * lalu menyerahkan kartu beserta kode akun. Status bayar (QRIS / manual) bisa ditandai saat itu atau nanti di detail kartu.
 */
class FieldActivationController extends PlatformController
{
    /** GET /api/field/card?q= — token, URL kartu, atau kode pendek tercetak → status kartu. */
    public function lookup(Request $request): JsonResponse
    {
        $this->can('cards.manage');
        $q = trim((string) $request->validate(['q' => ['required', 'string', 'max:300']])['q']);
        $card = $this->find($q) ?? throw new ApiError('card_not_found', 'Kartu tidak ditemukan. Periksa kode pendek di kartu.', 404);
        $ready = $card->status === CardStatus::Unclaimed || ($card->status === CardStatus::Manufactured && $card->qc_passed_at);

        return $this->ok([
            'token' => $card->card_token,
            'short_code' => $card->formattedShortCode(),
            'status' => $card->status->value,
            'status_label' => $card->status->label(),
            'ready' => $ready && ! $card->isDigital(),
            'reason' => match (true) {
                $card->isDigital() => 'Ini kartu digital, bukan kartu fisik.',
                $ready => null,
                $card->status === CardStatus::Manufactured => 'Kartu belum lolos QC.',
                in_array($card->status, [CardStatus::Active, CardStatus::Paused], true) => 'Kartu sudah aktif dan dipakai orang lain.',
                default => 'Kartu sudah tidak berlaku.',
            },
            'locked_until' => $card->activation_locked_until?->isFuture() ? self::dt($card->activation_locked_until) : null,
        ]);
    }

    /** POST /api/field/activate */
    public function activate(Request $request, FieldActivate $activate): JsonResponse
    {
        $this->can('cards.manage');
        $data = $request->validate([
            'token' => ['required', 'string', 'regex:/^[0-9A-Za-z]{10}$/'],
            'activation_code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'whatsapp' => ['nullable', 'string', 'max:32'],
            'title' => ['nullable', 'string', 'max:120'],
            'company' => ['nullable', 'string', 'max:120'],
            'amount' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'paid' => ['boolean'],
            'payment_method' => ['nullable', 'required_if:paid,true', Rule::in(array_keys(CardSale::METHODS))],
            'note' => ['nullable', 'string', 'max:200'],
        ], [
            'activation_code.required' => 'Masukkan kode aktivasi yang tercetak di kartu.',
            'payment_method.required_if' => 'Pilih cara bayar: QRIS atau manual.',
        ]);

        $r = $activate->handle($data['token'], $data['activation_code'], $data, [
            'amount' => $data['amount'] ?? 0, 'status' => ($data['paid'] ?? false) ? 'paid' : 'unpaid',
            'payment_method' => $data['payment_method'] ?? null, 'note' => $data['note'] ?? null,
        ], $request->user());

        return $this->ok([
            'customer' => ['id' => $r['user']->public_id, 'name' => $r['user']->name, 'email' => $r['user']->email, 'new_account' => $r['created']],
            'card' => ['token' => $r['card']->card_token, 'short_code' => $r['card']->formattedShortCode(), 'url' => $r['card']->resolverUrl()],
            'account_code' => $r['code'], // hanya sekali; yang disimpan hanya hash
            'sale' => $r['sale']->load(['buyer', 'seller'])->present(),
        ], 201);
    }

    /** PUT /api/cards/{token}/sale — tandai status bayar (QRIS / manual) & nominal penjualan langsung. */
    public function sale(Request $request, string $token): JsonResponse
    {
        $this->can('cards.manage');
        $card = Card::acrossTenants()->where('card_token', $token)->firstOrFail();
        $sale = CardSale::where('card_id', $card->id)->firstOrFail();
        $data = $request->validate([
            'status' => ['required', Rule::in(['paid', 'unpaid'])],
            'payment_method' => ['nullable', 'required_if:status,paid', Rule::in(array_keys(CardSale::METHODS))],
            'amount' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'note' => ['sometimes', 'nullable', 'string', 'max:200'],
        ], ['payment_method.required_if' => 'Pilih cara bayar: QRIS atau manual.']);

        $before = $sale->present();
        $sale->fill(array_intersect_key($data, array_flip(['amount', 'note'])));
        $sale->markPayment($data['status'], $data['payment_method'] ?? null);
        Audit::platform('card.sale_updated', $card, ['status' => $before['status'], 'method' => $before['payment_method'], 'amount' => $before['amount']],
            ['status' => $sale->status, 'method' => $sale->payment_method, 'amount' => $sale->amount]);

        return $this->ok($sale->load(['buyer', 'seller'])->present());
    }

    private function find(string $q): ?Card
    {
        if (preg_match('~/c/([0-9A-Za-z]{10})(?:[/?#]|$)~', $q, $m) || preg_match('/^[0-9A-Za-z]{10}$/', $q, $m)) {
            $card = Card::acrossTenants()->where('card_token', $m[1] ?? $m[0])->first();
            if ($card) {
                return $card;
            }
        }
        $short = Crockford::normalize($q);

        return strlen($short) === 8 ? Card::acrossTenants()->where('short_code', $short)->first() : null;
    }
}
