<?php

namespace App\Actions\Cards;

use App\Authz\PlanFeatures;
use App\Models\Card;
use App\Models\CardShareCode;
use App\Models\Tenant;
use App\Support\Base62;
use Illuminate\Support\Facades\DB;

/**
 * URL yang dipakai QR sebuah kartu. Kartu fisik & paket berbayar: permanen (/c/{token}?src=qr).
 * Kartu digital di paket dengan `qr_rotation_days` (Personal Free: 7): /s/{code} yang berganti otomatis
 * setiap periode; QR lama berhenti berlaku. Tautan profil (/c/{token}, @nama) tidak ikut berganti.
 */
final class ShareQr
{
    /** Panjang periode (hari) untuk kartu ini, atau null bila QR-nya permanen. */
    public static function rotationDays(Card $card): ?int
    {
        if (! $card->isDigital() || ! $card->tenant_id) {
            return null;
        }
        $tenant = Tenant::find($card->tenant_id);
        $days = $tenant ? PlanFeatures::limit($tenant, 'qr_rotation_days') : null;

        return $days && $days > 0 ? (int) $days : null;
    }

    public static function url(Card $card): string
    {
        $code = self::current($card);

        return $code ? config('tapcard.resolver_url').'/s/'.$code->code : $card->qrUrl();
    }

    /** Kode yang sedang berlaku (dibuat bila periode lalu sudah habis); null untuk QR permanen. */
    public static function current(Card $card): ?CardShareCode
    {
        $days = self::rotationDays($card);
        if ($days === null) {
            return null;
        }

        return DB::transaction(function () use ($card, $days) {
            Card::acrossTenants()->whereKey($card->id)->lockForUpdate()->first(); // satu kode per periode walau diminta bersamaan
            $code = CardShareCode::where('card_id', $card->id)->where('expires_at', '>', now())->latest('expires_at')->first();

            return $code ?? CardShareCode::create([
                'card_id' => $card->id, 'code' => Base62::random(12), 'starts_at' => now(), 'expires_at' => now()->addDays($days),
            ]);
        });
    }

    /** Tukar kode dengan URL profil (status kartu ditangani resolver); null bila tidak dikenal atau kedaluwarsa. */
    public static function redeem(string $code): ?string
    {
        $row = CardShareCode::where('code', $code)->first();
        $card = $row && $row->expires_at->isFuture() ? Card::acrossTenants()->find($row->card_id) : null;

        return $card?->qrUrl();
    }
}
