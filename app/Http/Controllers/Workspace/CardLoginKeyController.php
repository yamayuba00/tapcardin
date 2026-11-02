<?php

namespace App\Http\Controllers\Workspace;

use App\Actions\Auth\CardLogin;
use App\Enums\CardStatus;
use App\Models\Card;
use App\Models\CardLoginKey;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Pemegang kartu fisik mengatur PIN untuk "Masuk dengan kartu NFC". Hanya pemegang sendiri — admin
 * workspace yang boleh melihat kartu anggota tidak bisa membuat kunci login atas nama mereka.
 */
class CardLoginKeyController extends WorkspaceController
{
    /** Bentuk ringkas untuk halaman detail kartu; null bila kartu tidak bisa dipakai login. */
    public static function present(Card $card, int $userId): ?array
    {
        if ($card->holder_user_id !== $userId || ($card->form ?? Card::PHYSICAL) !== Card::PHYSICAL) {
            return null;
        }
        $key = CardLoginKey::where('card_id', $card->id)->where('user_id', $userId)->first();

        return [
            // UID chip diikat saat encode di produksi; tanpa itu kartu tidak bisa membuktikan dirinya
            'available' => $card->chip_uid_hash !== null && in_array($card->status, [CardStatus::Active, CardStatus::Paused], true),
            'enabled' => $key !== null,
            'last_used_at' => $key?->last_used_at?->toIso8601String(),
            'locked_until' => $key?->locked_until?->isFuture() ? $key->locked_until->toIso8601String() : null,
        ];
    }

    /** PUT /api/cards/{token}/login-key — aktifkan atau ganti PIN. */
    public function update(Request $request, string $token): JsonResponse
    {
        $card = $this->own($token);
        $state = self::present($card, $this->user()->id);
        if (! $state['available']) {
            throw ValidationException::withMessages(['pin' => 'Kartu ini belum bisa dipakai untuk masuk (chip belum terdaftar atau kartu tidak aktif).']);
        }
        $pin = (string) $request->validate(
            ['pin' => ['required', 'string', 'regex:/^\d{6}$/', 'confirmed']],
            ['pin.regex' => 'PIN terdiri dari 6 angka.', 'pin.confirmed' => 'Konfirmasi PIN tidak sama.'],
        )['pin'];
        if (CardLogin::weakPin($pin)) {
            throw ValidationException::withMessages(['pin' => 'PIN terlalu mudah ditebak. Hindari angka kembar atau berurutan.']);
        }

        CardLoginKey::where('card_id', $card->id)->delete(); // kunci pemegang sebelumnya (bila ada) ikut gugur
        CardLoginKey::create(['card_id' => $card->id, 'user_id' => $this->user()->id, 'pin_hash' => Hash::make($pin)]);
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
        abort_unless($card->holder_user_id === $this->user()->id && ($card->form ?? Card::PHYSICAL) === Card::PHYSICAL, 404);

        return $card;
    }
}
