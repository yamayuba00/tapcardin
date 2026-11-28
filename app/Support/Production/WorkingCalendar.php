<?php

namespace App\Support\Production;

use Carbon\CarbonImmutable;

/** Kalender hari kerja cetak: Senin–Jumat, kecuali tanggal di daftar libur (config production.holidays, Y-m-d). */
final class WorkingCalendar
{
    private const TZ = 'Asia/Jakarta';

    /** @var array<string, true> */
    private array $holidays;

    /** @param  list<string>  $holidays  tanggal libur Y-m-d */
    public function __construct(array $holidays = [])
    {
        $this->holidays = array_fill_keys(array_map('strval', $holidays), true);
    }

    public static function fromConfig(): self
    {
        return new self((array) config('production.holidays', []));
    }

    /** Tanggal awal (00:00 WIB) dari string Y-m-d. */
    public static function date(string $ymd): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $ymd, self::TZ)->startOfDay();
    }

    public function isWorkingDay(CarbonImmutable $day): bool
    {
        return $day->isoWeekday() <= 5 && ! isset($this->holidays[$day->format('Y-m-d')]);
    }

    /** Hari kerja pertama SETELAH tanggal $day (hari $day sendiri tidak dihitung). */
    public function nextWorkingDay(CarbonImmutable $day): CarbonImmutable
    {
        $day = $day->startOfDay()->addDay();
        while (! $this->isWorkingDay($day)) {
            $day = $day->addDay();
        }

        return $day;
    }

    /** Geser $n hari kerja ke depan dari $day (0 = tetap di $day). */
    public function addWorkingDays(CarbonImmutable $day, int $n): CarbonImmutable
    {
        for ($i = 0; $i < $n; $i++) {
            $day = $this->nextWorkingDay($day);
        }

        return $day;
    }

    /** Jumlah hari kerja dari $from (tidak termasuk) sampai $to (termasuk). */
    public function workingDaysBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $n = 0;
        for ($d = $from; $d->lt($to); $n++) {
            $d = $this->nextWorkingDay($d);
        }

        return $n;
    }
}
