<?php

namespace App\Console\Commands;

use App\Actions\Auth\DeleteAccount;
use App\Support\Uptime\StatusPage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** BSD §7.5 `tapcard:prune` — OTP, QR dinamis, sesi, cache kedaluwarsa, notifikasi lama. */
class Prune extends Command
{
    protected $signature = 'tapcard:prune';

    protected $description = 'Bersihkan data sementara (retensi TRD §12)';

    public function handle(): int
    {
        $counts = [
            'login_otps' => DB::table('login_otps')->where('created_at', '<', now()->subDay())->delete(),
            'dynamic_qr_tokens' => DB::table('dynamic_qr_tokens')->where('expires_at', '<', now()->subDay())->delete(),
            'sessions' => DB::table('sessions')->where('last_activity', '<', now()->subMinutes(config('session.lifetime'))->timestamp)->delete(),
            'cache' => DB::table('cache')->where('expiration', '<', time())->delete(),
            'notifications' => DB::table('notifications')->whereNotNull('read_at')->where('created_at', '<', now()->subDays(90))->delete(),
            'monitor_checks (> 90 hari)' => DB::table('monitor_checks')->where('checked_at', '<', now()->subDays(StatusPage::DAYS))->delete(),
            'users (hard delete > 30 hari)' => $this->purgeUsers(),
        ];
        foreach ($counts as $table => $n) {
            $this->line("$table: $n");
        }

        return self::SUCCESS;
    }

    /** TRD §12 — akun dihapus > 30 hari: data pribadi dihapus permanen, riwayat disimpan tanpa identitas (DeleteAccount). */
    private function purgeUsers(): int
    {
        $ids = DB::table('users')->whereNotNull('deleted_at')->where('deleted_at', '<', now()->subDays(DeleteAccount::RETENTION_DAYS))
            ->where('name', '!=', 'Pengguna dihapus')->pluck('id');
        $ids->each(fn ($id) => DeleteAccount::purge($id));

        return $ids->count();
    }
}
