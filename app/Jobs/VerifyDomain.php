<?php

namespace App\Jobs;

use App\Models\TenantDomain;
use App\Support\Audit;
use App\Support\Dns\DnsResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;

/** Cek TXT verifikasi domain di luar request (DNS lambat tidak menahan panel). */
class VerifyDomain implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public int $timeout = 15;

    public function __construct(public int $domainId)
    {
        $this->onQueue('default');
    }

    public static function checkingKey(int $domainId): string
    {
        return "domain:checking:$domainId";
    }

    public function handle(DnsResolver $dns): void
    {
        $domain = TenantDomain::find($this->domainId);
        if (! $domain) {
            return;
        }
        try {
            $found = $dns->txt($domain->txtName());
            $ok = in_array($domain->txtValue(), array_map('trim', $found), true);
            $domain->update([
                'last_checked_at' => now(),
                'last_error' => $ok ? null : ($found ? 'TXT ditemukan tetapi nilainya tidak cocok.' : 'TXT record belum ditemukan (propagasi DNS bisa sampai 24 jam).'),
                ...($ok ? ['status' => 'active', 'verified_at' => now()] : []),
            ]);
            Cache::forget("tenant:domain:{$domain->domain}");
            if ($ok) {
                Audit::platform('domain.verified', $domain, null, ['domain' => $domain->domain]);
            }
        } finally {
            Cache::forget(self::checkingKey($this->domainId));
        }
    }
}
