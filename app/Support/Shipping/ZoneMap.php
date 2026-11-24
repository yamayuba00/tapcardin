<?php

namespace App\Support\Shipping;

/** Provinsi → zona ongkir (semua 38 provinsi di App\Support\Indonesia). */
final class ZoneMap
{
    public const ZONES = ['Jawa-Bali', 'Sumatera', 'Kalimantan', 'Sulawesi', 'Nusa Tenggara & Maluku', 'Papua'];

    private const MAP = [
        'Jawa-Bali' => ['DKI Jakarta', 'Jawa Barat', 'Jawa Tengah', 'DI Yogyakarta', 'Jawa Timur', 'Banten', 'Bali'],
        'Sumatera' => ['Aceh', 'Sumatera Utara', 'Sumatera Barat', 'Riau', 'Jambi', 'Sumatera Selatan', 'Bengkulu', 'Lampung',
            'Kepulauan Bangka Belitung', 'Kepulauan Riau'],
        'Kalimantan' => ['Kalimantan Barat', 'Kalimantan Tengah', 'Kalimantan Selatan', 'Kalimantan Timur', 'Kalimantan Utara'],
        'Sulawesi' => ['Sulawesi Utara', 'Sulawesi Tengah', 'Sulawesi Selatan', 'Sulawesi Tenggara', 'Gorontalo', 'Sulawesi Barat'],
        'Nusa Tenggara & Maluku' => ['Nusa Tenggara Barat', 'Nusa Tenggara Timur', 'Maluku', 'Maluku Utara'],
        'Papua' => ['Papua', 'Papua Barat', 'Papua Barat Daya', 'Papua Pegunungan', 'Papua Selatan', 'Papua Tengah'],
    ];

    public static function zoneOf(string $province): ?string
    {
        foreach (self::MAP as $zone => $provinces) {
            if (in_array($province, $provinces, true)) {
                return $zone;
            }
        }

        return null;
    }
}
