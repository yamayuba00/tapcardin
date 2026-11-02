<?php

namespace App\Http\Controllers\Workspace;

use App\Actions\Auth\CardLogin;
use App\Enums\CardStatus;
use App\Models\Card;
use App\Models\CardLoginKey;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Pemegang kartu fisik mengatur kode akun 6 angka untuk "Masuk dengan kartu NFC". Hanya pemegang sendiri — admin
 * workspace yang boleh melihat kartu anggota tidak bisa membuat kunci login atas nama mereka.
 */
class CardLoginKeyController extends WorkspaceController
{
    /** Bentuk ringkas untuk halaman detail kartu; null bila kartu tidak bisa dipakai login. */
    public static function present(Card $card, int $userId): ?array
    {
        if ($card->holder_user_id !== $userId || $card->isDigital()) {
            return null;
        }
        $key = CardLoginKey::where('card_id', $card->id)->where('user_id', $userId)->first();

        return [
            'available' => in_array($card->status, [CardStatus::Active, CardStatus::Paused], true),
            'enabled' => $key !== null,
            'last_used_at' => $key?->last_used_at?->toIso8601String(),
            'locked_until' => $key?->locked_until?->isFuture() ? $key->locked_until->toIso8601String() : null,
        ];
    }

    /** PUT /api/cards/{token}/login-key — aktifkan atau ganti kode akun. */
    public function update(Request $request, string $token): JsonResponse
    {
        $card = $this->own($token);
        $state = self::present($card, $this->user()->id);
        if (! $state['available']) {
            throw ValidationException::withMessages(['code' => 'Kartu ini sedang tidak aktif, jadi belum bisa dipakai untuk masuk.']);
        }
        $code = (string) $request->validate(
            ['code' => ['required', 'string', 'regex:/^\d{6}$/', 'confirmed']],
            ['code.regex' => 'Kode akun terdiri dari 6 angka.', 'code.confirmed' => 'Konfirmasi kode tidak sama.'],
        )['code'];
        if (CardLogin::weakCode($code)) {
            throw ValidationException::withMessages(['code' => 'Kode terlalu mudah ditebak. Hindari angka kembar atau berurutan.']);
        }

        CardLogin::setCode($card, $this->user(), $code); // kunci pemegang sebelumnya (bila ada) ikut gugur
        Audit::tenant($card->tenant_id, 'card.login_key.set', $card);

        return response()->json(['data' => self::present($card, $this->user()->id)]);
    }

    /** DELETE /api/cards/{token}/login-key — matikan login dengan kartu ini. */
    public function destroy(string $token): JsonResponse
    {
        $card = $this->own($token);
        CardLoginKey::where('card_id', $card->id)->delete();
        Audit::tenant($card->tenant_id, 'card.login_key.removed', $card);

        return response()->json(['data' => self::present($card, $this->user()->id)]);
    }

    private function own(string $token): Card
    {
        $card = Card::where('card_token', $token)->firstOrFail();
        abort_unless($card->holder_user_id === $this->user()->id && ! $card->isDigital(), 404);

        return $card;
    }
}
