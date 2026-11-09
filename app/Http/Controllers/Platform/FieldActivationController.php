<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Cards\FieldActivate;
use App\Actions\Production\EncodeCard;
use App\Enums\CardStatus;
use App\Exceptions\ApiError;
use App\Models\Batch;
use App\Models\Card;
use App\Models\CardSale;
use App\Models\User;
use App\Support\Audit;
use App\Support\Crockford;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * Aktivasi di tempat (penjualan langsung): staf menempelkan / mengetik kartu, mengisi data pelanggan & kode aktivasi,
 * lalu menyerahkan kartu beserta kode akun. Status bayar (QRIS / manual) bisa ditandai saat itu atau nanti di detail kartu.
 */
class FieldActivationController extends PlatformController
{
    /** Batch yang kartunya boleh dijual dari stok (token sudah dibuat, belum selesai/dibatalkan). */
    private const STOCK_BATCH = ['tokens_generated', 'sent_to_print', 'printed', 'encoding', 'qc'];

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
            'batch' => $card->batch_id ? ['code' => Batch::whereKey($card->batch_id)->value('code'), 'sequence' => $card->sequence] : null,
            'reserved_for' => $card->assigned_user_id ? User::whereKey($card->assigned_user_id)->value('email') : null,
        ]);
    }

    /** POST /api/field/activate */
    public function activate(Request $request, FieldActivate $activate): JsonResponse
    {
        $this->can('cards.manage');
        $data = $request->validate([
            'token' => ['required', 'string', 'regex:/^[0-9A-Za-z]{10}$/'],
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
            'payment_method.required_if' => 'Pilih cara bayar: QRIS atau manual.',
        ]);

        $r = $activate->handle($data['token'], $data, [
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

    /**
     * POST /api/field/blank — kartu POLOS ditempel di Jual kartu: token berikutnya yang belum di-encode dari stok batch
     * desain seragam (bukan milik pesanan/workspace). Dicadangkan 2 menit agar dua petugas tidak menulis token yang sama.
     */
    public function blank(): JsonResponse
    {
        $this->can('cards.manage');
        $staff = auth()->id();
        $card = Card::acrossTenants()->whereNull('encoded_at')->where('status', CardStatus::Manufactured->value)
            ->whereNull('tenant_id')->whereNull('assigned_user_id')
            ->whereIn('batch_id', Batch::where('uniform', true)->whereIn('status', self::STOCK_BATCH)->select('id'))
            ->orderBy('batch_id')->orderBy('sequence')->limit(25)->get()
            ->first(fn (Card $c) => Cache::add("field:blank:{$c->id}", $staff, 120) || Cache::get("field:blank:{$c->id}") === $staff);
        if (! $card) {
            throw new ApiError('no_blank_stock', 'Stok kartu polos habis. Buat batch "desain seragam" lalu buat tokennya, atau pakai kartu yang sudah di-encode.');
        }

        return $this->ok(['token' => $card->card_token, 'url' => $card->chipUrl(), 'short_code' => $card->formattedShortCode()]);
    }

    /** POST /api/field/cards/{token}/encoded {uid} — chip kartu polos sudah ditulis & diverifikasi → lolos QC (desain seragam). */
    public function encoded(Request $request, string $token, EncodeCard $encode): JsonResponse
    {
        $this->can('cards.manage');
        $data = $request->validate(['uid' => ['required', 'string', 'max:40']]);
        $card = Card::acrossTenants()->where('card_token', $token)->firstOrFail();
        abort_unless($card->batch?->uniform && Cache::get("field:blank:{$card->id}") === auth()->id(), 403, 'Kartu ini tidak dicadangkan untuk Anda.');
        $card = $encode->handle($card, $data['uid']);
        Cache::forget("field:blank:{$card->id}");

        return $this->ok(['token' => $card->card_token, 'ready' => $card->qc_passed_at !== null]);
    }

    /** GET /api/field/summary — penjualan petugas ini hari ini (WIB) + stok siap jual. */
    public function summary(): JsonResponse
    {
        $this->can('cards.manage');
        $today = CardSale::where('sold_by_user_id', auth()->id())->where('created_at', '>=', now('Asia/Jakarta')->startOfDay());
        $stock = fn () => Card::acrossTenants()->where('status', CardStatus::Manufactured->value)->whereNull('tenant_id')->whereNull('assigned_user_id');

        return $this->ok([
            'today' => ['count' => (clone $today)->count(), 'total' => (int) (clone $today)->sum('amount'), 'unpaid' => (clone $today)->where('status', 'unpaid')->count()],
            'stock' => [
                'ready' => $stock()->whereNotNull('qc_passed_at')->count(), // sudah di-encode & lolos QC
                'blank' => $stock()->whereNull('encoded_at')->whereIn('batch_id', Batch::where('uniform', true)->whereIn('status', self::STOCK_BATCH)->select('id'))->count(),
            ],
        ]);
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
