<?php

namespace App\Console\Commands;

use App\Models\TenantDomain;
use App\Support\Dns\DnsResolver;
use App\Support\Notify;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/** Cek ulang TXT domain aktif. Gagal 2 kali berturut-turut → pending + pemilik diberi tahu sekali. */
class DomainsRecheck extends Command
{
    private const MISSES_BEFORE_PENDING = 2;

    protected $signature = 'tapcard:domains-recheck';

    protected $description = 'Periksa ulang TXT verifikasi domain kustom yang aktif';

    public function handle(DnsResolver $dns): int
    {
        $n = ['ok' => 0, 'pending' => 0];
        TenantDomain::withoutGlobalScopes()->where('status', 'active')->with('tenant')->chunkById(200, function ($rows) use ($dns, &$n) {
            foreach ($rows as $d) {
                $found = array_map('trim', $dns->txt($d->txtName()));
                $key = "domain:miss:{$d->id}";
                if (in_array($d->txtValue(), $found, true)) {
                    Cache::forget($key);
                    $n['ok']++;

                    continue;
                }
                Cache::add($key, 0, 86400);
                if (Cache::increment($key) < self::MISSES_BEFORE_PENDING) {
                    continue;
                }
                Cache::forget($key);
                $d->update(['status' => 'pending', 'last_checked_at' => now(), 'last_error' => 'TXT verifikasi hilang']);
                Cache::forget("tenant:domain:{$d->domain}");
                $n['pending']++;
                $tenant = $d->tenant;
                if ($tenant && $tenant->owner_user_id) {
                    Notify::user($tenant->owner_user_id, $tenant->id, 'domain_unverified', ['domain' => $d->domain]);
                }
            }
        });
        $this->info("Domain aktif terverifikasi: {$n['ok']}; menjadi pending: {$n['pending']}");

        return self::SUCCESS;
    }
}
