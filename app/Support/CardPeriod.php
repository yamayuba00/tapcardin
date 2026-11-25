<?php

namespace App\Support;

use App\Models\PlatformSetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Aturan masa pakai kartu penjualan langsung: 6 bulan dari aktivasi, tenggang 7 hari, perpanjangan dari sisa masa. */
final class CardPeriod
{
    public static function months(): int
    {
        return (int) config('tapcard.card_period.months');
    }

    public static function graceDays(): int
    {
        return (int) config('tapcard.card_period.grace_days');
    }

    public static function renewalPrice(): int
    {
        return (int) PlatformSetting::get('card_renewal.price', config('tapcard.card_period.renewal_price'));
    }

    /** Lewat masa tenggang: kartu nonaktif. Kartu tanpa batas (null) tidak pernah kedaluwarsa. */
    public static function expired(?CarbonInterface $validUntil, ?CarbonInterface $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        return $validUntil !== null && $now->greaterThan(CarbonImmutable::parse($validUntil)->addDays(self::graceDays()));
    }

    /** Awal perpanjangan: dari akhir masa sekarang bila masih dalam tenggang, selain itu dari sekarang. */
    public static function renewalBase(?CarbonInterface $validUntil, ?CarbonInterface $now = null): CarbonImmutable
    {
        $now = $now ? CarbonImmutable::parse($now) : CarbonImmutable::now();

        return $validUntil !== null && ! self::expired($validUntil, $now)
            ? CarbonImmutable::parse($validUntil)
            : $now;
    }
}
