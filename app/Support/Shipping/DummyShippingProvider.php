<?php

namespace App\Support\Shipping;

use App\Models\ShippingZoneRate;
use InvalidArgumentException;

/** Penyedia ongkir simulasi: tarif dari tabel placeholder, pelacakan deterministik per nomor resi. */
final class DummyShippingProvider implements ShippingProvider
{
    private const STEPS = [
        ['accepted', 'Paket diterima kurir', 'Gudang asal'],
        ['picked_up', 'Paket dijemput', 'Gudang asal'],
        ['in_transit', 'Dalam perjalanan', 'Pusat sortir'],
        ['delivered', 'Paket diterima penerima', 'Alamat tujuan'],
    ];

    public function cost(string $originZone, string $destProvince, int $weightGram, string $courier): array
    {
        $zone = ZoneMap::zoneOf($destProvince) ?? throw new InvalidArgumentException("Provinsi tidak dikenal: $destProvince");
        $kg = max(1, (int) ceil($weightGram / 1000));

        return ShippingZoneRate::where('zone_code', $zone)->where('courier', $courier)->orderBy('service')->get()
            ->map(fn (ShippingZoneRate $r) => [
                'code' => $r->courier.'-'.$r->service, 'service' => $r->service,
                'description' => $r->service === 'YES' ? 'Layanan cepat' : 'Layanan reguler',
                'cost' => $r->base_cost + $kg * $r->per_kg_cost,
                'etd' => $r->etd_min_days === $r->etd_max_days ? "{$r->etd_min_days} hari" : "{$r->etd_min_days}–{$r->etd_max_days} hari",
            ])->values()->all();
    }

    public function track(string $awb, string $courier): array
    {
        $steps = 1 + (crc32($courier.'|'.$awb) % count(self::STEPS));
        $base = strtotime('2026-01-01 08:00:00 UTC');
        $history = [];
        foreach (array_slice(self::STEPS, 0, $steps) as $i => [$status, $label, $location]) {
            $history[] = ['at' => gmdate('c', $base + $i * 7200), 'status' => $status, 'location' => $location, 'label' => $label];
        }
        $last = end($history);

        return ['status' => $last['status'], 'summary' => $last['label'], 'history' => array_reverse($history), 'simulated' => true];
    }

    public function couriers(): array
    {
        return ['JNE', 'J&T', 'SiCepat'];
    }

    public function simulated(): bool
    {
        return true;
    }
}
