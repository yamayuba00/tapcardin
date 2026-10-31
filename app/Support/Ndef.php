<?php

namespace App\Support;

use InvalidArgumentException;

/** TRD §7.1 — satu record NDEF Well-Known type `U` (URI), dibungkus TLV. */
final class Ndef
{
    /** Prefix URI yang didukung (NFC Forum URI RTD). */
    private const PREFIXES = [
        0x04 => 'https://',
        0x03 => 'http://',
        0x02 => 'https://www.',
        0x01 => 'http://www.',
    ];

    public static function uriRecord(string $url): string
    {
        $code = 0x00;
        $rest = $url;
        foreach (self::PREFIXES as $c => $prefix) {
            if (str_starts_with($url, $prefix)) {
                $code = $c;
                $rest = substr($url, strlen($prefix));
                break;
            }
        }
        $payload = chr($code).$rest;
        if (strlen($payload) > 255) {
            throw new InvalidArgumentException('URL terlalu panjang untuk short record.');
        }

        // Header: MB=1 ME=1 CF=0 SR=1 IL=0 TNF=0x01 (Well-Known) → 0xD1
        return "\xD1".chr(1).chr(strlen($payload)).'U'.$payload;
    }

    /** NDEF Message TLV (0x03) + Terminator TLV (0xFE), siap ditulis ke halaman 4 NTAG21x. */
    public static function tlv(string $url): string
    {
        $record = self::uriRecord($url);

        return "\x03".chr(strlen($record)).$record."\xFE";
    }

    public static function fitsNtag213(string $url): bool
    {
        return strlen(self::tlv($url)) <= 144;
    }
}
