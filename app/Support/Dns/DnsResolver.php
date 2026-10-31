<?php

namespace App\Support\Dns;

/** Pencarian TXT record (dibungkus agar bisa diganti di test). */
class DnsResolver
{
    /** @return list<string> */
    public function txt(string $name): array
    {
        $records = @dns_get_record($name, DNS_TXT) ?: [];

        return array_values(array_filter(array_map(fn ($r) => $r['txt'] ?? implode('', $r['entries'] ?? []), $records)));
    }
}
