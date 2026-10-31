<?php

namespace App\Enums;

/** AFD §4.1 — status hanya mencerminkan apa yang dilihat pengunjung. */
enum CardStatus: string
{
    case Manufactured = 'manufactured';
    case Unclaimed = 'unclaimed';
    case Active = 'active';
    case Paused = 'paused';
    case Lost = 'lost';
    case Revoked = 'revoked';

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Manufactured => [self::Unclaimed, self::Revoked],
            self::Unclaimed => [self::Active, self::Revoked],
            self::Active => [self::Paused, self::Lost, self::Unclaimed],
            self::Paused => [self::Active, self::Lost],
            self::Lost => [self::Active, self::Revoked],
            self::Revoked => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Manufactured => 'Diproduksi',
            self::Unclaimed => 'Belum diaktifkan',
            self::Active => 'Aktif',
            self::Paused => 'Dijeda',
            self::Lost => 'Hilang',
            self::Revoked => 'Tidak berlaku',
        };
    }

    /** DBD §3.5 — token warna status. */
    public function tone(): string
    {
        return match ($this) {
            self::Active => 'ok',
            self::Unclaimed => 'info',
            self::Paused => 'warn',
            self::Lost => 'danger',
            self::Revoked, self::Manufactured => 'muted',
        };
    }
}
