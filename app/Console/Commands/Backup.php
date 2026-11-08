<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Backup database harian ke luar server: `pg_dump -Fc` (terkompresi) → disk `tapcard.backup.disk` (S3 folder
 * backups/, privat) → salinan lebih tua dari `keep_days` dihapus. Berhasil → `backup:last` (dibaca tapcard:health,
 * alarm bila > 26 jam). Gagal → exit 1 + error dilaporkan (ikut dihitung alarm).
 *
 * Pulihkan: unduh file .dump lalu `pg_restore --clean --no-owner -d <database> tapcard-....dump`.
 */
class Backup extends Command
{
    protected $signature = 'tapcard:backup';

    protected $description = 'Backup database ke penyimpanan di luar server (S3)';

    public function handle(): int
    {
        $diskName = config('tapcard.backup.disk');
        if (! $diskName) {
            $this->warn('BACKUP_DISK belum diisi — backup dilewati.');

            return self::SUCCESS;
        }
        $db = config('database.connections.'.config('database.default'));
        $name = 'tapcard-'.now('Asia/Jakarta')->format('Ymd-His').'.dump';
        $local = storage_path('app/'.$name);

        try {
            $result = Process::timeout(3600)->env([
                'PGPASSWORD' => (string) $db['password'],
                'PGSSLMODE' => (string) ($db['sslmode'] ?? 'prefer'),
            ])->run([
                'pg_dump', '--format=custom', '--no-owner', '--no-privileges', '--file='.$local,
                '--host='.(config('tapcard.backup.db_host') ?: $db['host']),
                '--port='.(config('tapcard.backup.db_port') ?: $db['port']),
                '--username='.$db['username'], $db['database'],
            ]);
            if (! $result->successful() || ! is_file($local) || filesize($local) === 0) {
                throw new \RuntimeException('pg_dump gagal: '.trim($result->errorOutput() ?: 'file kosong'));
            }
            $size = filesize($local);

            $disk = Storage::disk($diskName);
            $stream = fopen($local, 'r');
            $disk->writeStream($name, $stream);
            is_resource($stream) && fclose($stream);
            if ($disk->size($name) !== $size) {
                throw new \RuntimeException("Ukuran file di penyimpanan tidak sama ($size byte lokal).");
            }

            $deleted = $this->prune($disk);
            Cache::forever('backup:last', ['at' => now()->timestamp, 'size' => $size, 'file' => $name]);
            $this->info(sprintf('Backup %s (%s MB) tersimpan; %d backup lama dihapus.', $name, number_format($size / 1048576, 1), $deleted));

            return self::SUCCESS;
        } catch (Throwable $e) {
            report($e);
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            @unlink($local);
        }
    }

    /** Hapus backup lebih tua dari keep_days (tanggal dari nama file). Backup terbaru tidak pernah ikut terhapus. */
    private function prune($disk): int
    {
        $cutoff = now('Asia/Jakarta')->subDays((int) config('tapcard.backup.keep_days'))->format('Ymd');
        $old = collect($disk->files())->filter(fn ($f) => preg_match('/^tapcard-(\d{8})-\d{6}\.dump$/', $f, $m) && $m[1] < $cutoff)->values();
        $old->each(fn ($f) => $disk->delete($f));

        return $old->count();
    }
}
