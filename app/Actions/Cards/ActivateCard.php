<?php

namespace App\Actions\Cards;

use App\Actions\Profiles\ProvisionCardProfile;
use App\Authz\PlanFeatures;
use App\Enums\CardStatus;
use App\Enums\TenantType;
use App\Exceptions\ApiError;
use App\Models\Card;
use App\Models\ProductEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Crockford;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * PRD §9.1 / AFD F-05 — aktivasi butuh kartu fisik + (login sebagai pembeli ATAU kode aktivasi 8 karakter).
 * Kode disimpan sebagai hash, sekali pakai, terkunci 1 jam setelah 5 kali salah.
 */
final class ActivateCard
{
    public const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly ChangeCardStatus $status,
        private readonly CurrentTenant $current,
        private readonly ProvisionCardProfile $provision,
    ) {}

    public function handle(string $token, User $user, ?string $code = null): Card
    {
        $card = $this->find($token);
        if ($card->activation_locked_until?->isFuture()) {
            throw new ApiError('activation_locked',
                'Terlalu banyak percobaan. Coba lagi pukul '.$card->activation_locked_until->timezone($user->timezone)->format('H.i').'.', 423);
        }

        $cardTenant = $card->tenant_id ? Tenant::find($card->tenant_id) : null;
        $isCompanyCard = $cardTenant && $cardTenant->type !== TenantType::Personal;
        if ($isCompanyCard && $card->assigned_user_id !== $user->id) {
            // AFD F-06: kartu perusahaan hanya bisa diaktifkan oleh anggota yang ditugaskan
            throw new ApiError('card_assigned_elsewhere', 'Kartu ini ditugaskan untuk email lain.', 403);
        }

        $isBuyer = $card->assigned_user_id === $user->id;
        if (! $isBuyer) {
            $this->checkCode($card, $code); // di luar transaksi agar hitungan percobaan tetap tersimpan
        }

        return DB::transaction(function () use ($token, $user, $isBuyer, $isCompanyCard, $cardTenant) {
            $card = $this->find($token, lock: true);
            $tenant = $isCompanyCard ? $cardTenant : $user->personalTenant;
            $this->ensureWithinLimit($tenant);

            // Kartu pengganti mewarisi bio kartu yang hilang; kartu lain mendapat bio sendiri (1 kartu = 1 bio).
            $old = $card->replaces_card_id ? Card::acrossTenants()->find($card->replaces_card_id) : null;
            $old = $old && $old->status === CardStatus::Lost ? $old : null;
            $profileId = $old?->profile_id
                ? $old->profile_id
                : $this->current->run($tenant, fn () => $this->provision->handle($user, $card))->id;

            $this->status->handle($card, CardStatus::Active, 'Aktivasi', extra: [
                'tenant_id' => $tenant->id,
                'holder_user_id' => $user->id,
                'assigned_user_id' => null,
                'profile_id' => $profileId,
                'activation_code_hash' => null,
                'activation_attempts' => 0,
                'activation_locked_until' => null,
            ]);

            // Kartu pengganti mengambil alih profil yang sama; kartu lama dicabut (AFD F-13).
            if ($old) {
                $this->status->handle($old, CardStatus::Revoked, 'Diganti kartu baru');
            }

            ProductEvent::record('card_activated', $tenant->id, $user->id, ['via' => $isBuyer ? 'buyer' : 'code']);

            return $card->fresh();
        });
    }

    private function find(string $token, bool $lock = false): Card
    {
        $card = Card::acrossTenants()->where('card_token', $token)
            ->when($lock, fn ($q) => $q->lockForUpdate())->first();
        if (! $card || $card->status !== CardStatus::Unclaimed) {
            throw new ApiError('card_not_activatable', 'Kartu ini tidak bisa diaktifkan.', 404);
        }

        return $card;
    }

    /** Cek kode aktivasi (percobaan salah dihitung & dikunci) — juga dipakai aktivasi di tempat oleh staf. */
    public function checkCode(Card $card, ?string $code): void
    {
        $normalized = Crockford::normalize((string) $code);
        if ($code === null || $normalized === '') {
            throw new ApiError('activation_code_required', 'Masukkan kode aktivasi 8 karakter yang dikirim bersama kartu.');
        }
        if ($card->activation_code_hash && Hash::check($normalized, $card->activation_code_hash)) {
            return;
        }

        $attempts = $card->activation_attempts + 1;
        if ($attempts >= self::MAX_ATTEMPTS) {
            $card->update(['activation_attempts' => 0, 'activation_locked_until' => now()->addHour()]);
            throw new ApiError('activation_locked', 'Kode salah 5 kali. Kartu terkunci 1 jam.', 423);
        }
        $card->update(['activation_attempts' => $attempts]);
        $left = self::MAX_ATTEMPTS - $attempts;
        throw new ApiError('activation_code_invalid', "Kode belum cocok. Sisa $left percobaan.");
    }

    private function ensureWithinLimit(Tenant $tenant): void
    {
        $limit = PlanFeatures::limit($tenant, 'cards_active');
        if ($limit === null) {
            return;
        }
        $active = Card::acrossTenants()->where('tenant_id', $tenant->id)->where('form', Card::PHYSICAL) // kartu digital gratis
            ->whereIn('status', [CardStatus::Active->value, CardStatus::Paused->value])->count();
        if ($active >= $limit) {
            throw ApiError::planLimit("Paket Anda mendukung $limit kartu aktif. Upgrade untuk menambah kartu.", 'cards_active');
        }
    }
}
