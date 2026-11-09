<?php

namespace App\Actions\Cards;

use App\Actions\Auth\CardLogin;
use App\Actions\Auth\RegisterUser;
use App\Enums\CardStatus;
use App\Enums\TenantType;
use App\Exceptions\ApiError;
use App\Models\Card;
use App\Models\CardSale;
use App\Models\Profile;
use App\Models\ProfileField;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit;
use App\Support\Phone;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;

/**
 * Aktivasi di tempat (penjualan langsung / door to door) oleh staf panel:
 * kartu fisik yang lolos QC → akun pelanggan dibuat (atau dipakai bila email sudah terdaftar),
 * kartu langsung aktif dengan bio awal dari isian form, kode akun 6 angka acak untuk masuk dengan kartu,
 * dan catatan penjualan (status bayar QRIS / manual ditandai staf).
 *
 * Kode akun hanya dikembalikan sekali (yang disimpan hanya hash) — diserahkan ke pelanggan, bisa diganti nanti.
 *
 * Tanpa kode aktivasi: kode itu melindungi kartu dari orang asing yang menemukannya; staf panel (cards.manage) memegang
 * kartunya sendiri dan setiap aktivasi tercatat di audit atas namanya. Pemilik yang mengaktifkan sendiri lewat tap
 * tetap memakai kode (ActivateCard). Kartu yang sudah dipesan pembeli online tidak bisa dialihkan ke orang lain.
 */
final class FieldActivate
{
    public function __construct(
        private readonly ActivateCard $activate,
        private readonly ChangeCardStatus $status,
        private readonly RegisterUser $register,
        private readonly CurrentTenant $current,
    ) {}

    /**
     * @param  array{name:string, email:string, whatsapp?:?string, title?:?string, company?:?string}  $customer
     * @param  array{amount?:int, status?:string, payment_method?:?string, note?:?string}  $sale
     * @return array{user: User, card: Card, code: string, created: bool, sale: CardSale}
     */
    public function handle(string $token, array $customer, array $sale, User $staff): array
    {
        $card = Card::acrossTenants()->where('card_token', $token)->first();
        if (! $card || $card->isDigital()) {
            throw new ApiError('card_not_found', 'Kartu tidak dikenal.', 404);
        }
        if ($card->tenant_id && Tenant::find($card->tenant_id)?->type !== TenantType::Personal) {
            throw new ApiError('company_card', 'Kartu perusahaan diaktifkan oleh anggota workspace-nya, bukan lewat penjualan langsung.');
        }
        // stok yang lolos QC tapi belum "dikirim": dilepas dulu ke status siap diaktifkan
        if ($card->status === CardStatus::Manufactured && $card->qc_passed_at) {
            $this->status->handle($card, CardStatus::Unclaimed, 'Dijual langsung (aktivasi di tempat)', platform: true);
            $card->refresh();
        }
        if ($card->status !== CardStatus::Unclaimed) {
            throw new ApiError('card_not_activatable', match ($card->status) {
                CardStatus::Active, CardStatus::Paused => 'Kartu ini sudah aktif dan dipakai orang lain.',
                CardStatus::Manufactured => 'Kartu ini belum lolos QC, jadi belum boleh dijual.',
                default => 'Kartu ini sudah tidak berlaku.',
            });
        }

        $reserved = $card->assigned_user_id ? User::find($card->assigned_user_id) : null;
        if ($reserved && strtolower($reserved->email) !== strtolower(trim($customer['email']))) {
            throw new ApiError('card_reserved', "Kartu ini milik pesanan {$reserved->email}. Pakai kartu stok lain, atau isi email pemesannya.");
        }

        return DB::transaction(function () use ($card, $customer, $sale, $staff) {
            $email = strtolower(trim($customer['email']));
            $user = User::whereRaw('lower(email) = ?', [$email])->first();
            $created = $user === null;
            if ($user?->isBlocked()) {
                throw new ApiError('user_blocked', 'Akun dengan email ini diblokir.');
            }
            if ($created) {
                $user = $this->register->handle($email, $customer['name']);
                // disalin ke bio kartu fisik saat aktivasi
                $this->current->run($user->personalTenant, fn () => $this->fillStarterProfile($user, $customer));
            }

            // pelanggan = pembeli: aktivasi tanpa kode lagi (kode sudah dicek di atas)
            $card->forceFill(['assigned_user_id' => $user->id])->save();
            $card = $this->activate->handle($card->card_token, $user);

            $code = CardLogin::randomCode();
            CardLogin::setCode($card, $user, $code);

            $paid = ($sale['status'] ?? 'unpaid') === 'paid';
            $record = CardSale::create([
                'card_id' => $card->id, 'buyer_user_id' => $user->id, 'sold_by_user_id' => $staff->id,
                'amount' => (int) ($sale['amount'] ?? 0), 'note' => $sale['note'] ?? null,
                'status' => $paid ? 'paid' : 'unpaid', 'payment_method' => $sale['payment_method'] ?? null, 'paid_at' => $paid ? now() : null,
            ]);
            Audit::platform('card.field_activated', $card, null, [
                'buyer' => $user->email, 'new_account' => $created, 'amount' => $record->amount, 'payment' => $record->status,
            ]);

            return ['user' => $user, 'card' => $card, 'code' => $code, 'created' => $created, 'sale' => $record];
        });
    }

    /** Bio awal akun baru: jabatan, perusahaan, WhatsApp dari form petugas. */
    private function fillStarterProfile(User $user, array $c): void
    {
        $profile = Profile::where('user_id', $user->id)->orderBy('id')->first();
        if (! $profile) {
            return;
        }
        $profile->forceFill(array_filter([
            'title' => $c['title'] ?? null,
            'company' => $c['company'] ?? null,
        ]))->save();

        if (! empty($c['whatsapp'])) {
            ProfileField::create([
                'profile_id' => $profile->id, 'type' => 'whatsapp', 'label' => 'WhatsApp',
                'value' => Phone::toE164($c['whatsapp']) ?? $c['whatsapp'], 'is_primary' => true, 'sort' => -1,
            ]);
        }
    }
}
