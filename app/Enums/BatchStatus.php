<?php

namespace App\Enums;

/** AFD §4.3 */
enum BatchStatus: string
{
    case Draft = 'draft';
    case TokensGenerated = 'tokens_generated';
    case SentToPrint = 'sent_to_print';
    case Printed = 'printed';
    case Encoding = 'encoding';
    case Qc = 'qc';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::TokensGenerated, self::Cancelled],
            self::TokensGenerated => [self::SentToPrint, self::Cancelled],
            self::SentToPrint => [self::Printed],
            self::Printed => [self::Encoding],
            self::Encoding => [self::Qc],
            self::Qc => [self::Completed],
            self::Completed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::TokensGenerated => 'Token dibuat',
            self::SentToPrint => 'Dikirim ke vendor',
            self::Printed => 'Tercetak',
            self::Encoding => 'Encode',
            self::Qc => 'QC',
            self::Completed => 'Selesai',
            self::Cancelled => 'Dibatalkan',
        };
    }
}
