<?php

namespace App\Support\Webhooks;

/** Resolusi DNS host tujuan webhook (A + AAAA); dibungkus agar bisa diganti di tes. */
class HostResolver
{
    /** @return list<string> */
    public function ips(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $ips = @gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $r) {
            if (! empty($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
