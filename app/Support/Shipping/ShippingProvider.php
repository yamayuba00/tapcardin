<?php

namespace App\Support\Shipping;

/** Kontrak ongkir & pelacakan kurir. Penyedia sungguhan (mis. Komerce/RajaOngkir) mengganti dummy tanpa mengubah pemanggil. */
interface ShippingProvider
{
    /** @return list<array{code:string, service:string, description:string, cost:int, etd:string}> */
    public function cost(string $originZone, string $destProvince, int $weightGram, string $courier): array;

    /** @return array{status:string, summary:string, history:list<array{at:string, status:string, location:string}>, simulated:bool} */
    public function track(string $awb, string $courier): array;

    /** @return list<string> */
    public function couriers(): array;

    public function simulated(): bool;
}
