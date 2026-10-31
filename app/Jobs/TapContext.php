<?php

namespace App\Jobs;

use App\Enums\TapSource;
use App\Support\Hasher;
use App\Support\UserAgent;
use Illuminate\Http\Request;

/** Data tap yang sudah di-hash sebelum masuk antrean (TRD §6.2 langkah 4). */
final class TapContext
{
    public function __construct(
        public readonly string $source,
        public readonly ?string $sunResult,
        public readonly string $ipHash,
        public readonly string $visitorHash,
        public readonly ?string $country,
        public readonly ?string $city,
        public readonly string $device,
        public readonly ?string $browser,
        public readonly string $at,
    ) {}

    /** @param  TapSource|null  $source  paksa sumber (mis. `app` untuk Mode Reader); bawaan dari `?src=` */
    public static function fromRequest(Request $request, ?string $sunResult = null, ?TapSource $source = null): self
    {
        $src = $source ?? TapSource::tryFrom((string) $request->query('src', 'nfc')) ?? TapSource::Nfc;
        $ua = $request->userAgent();
        $date = now('Asia/Jakarta')->toDateString();
        $country = $request->header('CF-IPCountry');

        return new self(
            source: $src->value,
            sunResult: $sunResult,
            ipHash: Hasher::ip($request->ip()),
            visitorHash: Hasher::visitor($request->ip(), $ua, $date),
            country: $country && strlen($country) === 2 && $country !== 'XX' ? strtoupper($country) : null,
            city: $request->header('cf-ipcity') ? mb_substr($request->header('cf-ipcity'), 0, 80) : null,
            device: UserAgent::device($ua),
            browser: UserAgent::browser($ua),
            at: now()->toIso8601String(),
        );
    }
}
