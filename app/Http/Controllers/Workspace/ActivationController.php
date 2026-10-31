<?php

namespace App\Http\Controllers\Workspace;

use App\Actions\Cards\ActivateCard;
use App\Enums\CardStatus;
use App\Exceptions\ApiError;
use App\Models\Card;
use App\Support\Crockford;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** AFD F-05 — info kartu untuk halaman aktivasi & aksi aktivasi. Wizard U-16 memakai API kartu/profil. */
class ActivationController extends WorkspaceController
{
    public function info(string $token): JsonResponse
    {
        $card = Card::acrossTenants()->where('card_token', $token)->firstOrFail();
        $user = $this->user();

        return response()->json(['data' => [
            'token' => $card->card_token,
            'short_code' => $card->formattedShortCode(),
            'status' => $card->status->value,
            'is_buyer' => $card->assigned_user_id === $user->id,
            'already_mine' => $card->status === CardStatus::Active && $card->holder_user_id === $user->id,
            'locked_until' => $card->activation_locked_until?->isFuture() ? $card->activation_locked_until->toIso8601String() : null,
            'email' => $user->email,
        ]]);
    }

    /**
     * POST /api/cards/claim — tambah kartu dari dalam aplikasi tanpa tap/scan (laptop, chip bermasalah):
     * kode pendek tercetak di kartu + kode aktivasi dari kemasan (pembeli tidak perlu kode).
     */
    public function claim(Request $request, ActivateCard $activate): JsonResponse
    {
        $data = $request->validate([
            'short_code' => ['required', 'string', 'max:20'],
            'code' => ['nullable', 'string', 'max:20'],
        ], ['short_code.required' => 'Masukkan kode kartu 8 karakter.']);
        $short = Crockford::normalize($data['short_code']);
        $token = Crockford::isValid($short) ? Card::acrossTenants()->where('short_code', $short)->value('card_token') : null;
        if (! $token) {
            throw new ApiError('card_not_found', 'Kode kartu belum dikenali. Periksa 8 karakter di dekat QR.', 404);
        }

        return $this->respond($activate->handle($token, $this->user(), $data['code'] ?? null));
    }

    public function activate(Request $request, ActivateCard $activate): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'size:10'],
            'code' => ['nullable', 'string', 'max:20'],
        ]);

        return $this->respond($activate->handle($data['token'], $this->user(), $data['code'] ?? null));
    }

    private function respond(Card $card): JsonResponse
    {
        return response()->json(['data' => [
            'card' => ['token' => $card->card_token, 'status' => $card->status->value],
            'next' => "/cards/{$card->card_token}/setup",
        ]]);
    }
}
