<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Pindahkan unggahan publik lama (storage/app/public) ke bucket S3 (folder public/), lalu set PUBLIC_DISK_DRIVER=s3.
 * Aman diulang: file yang sudah ada di bucket dengan ukuran sama dilewati. File lokal tidak dihapus.
 */
class StorageToS3 extends Command
{
    protected $signature = 'tapcard:storage-to-s3 {--dry-run : hanya hitung, tanpa mengunggah}';

    protected $description = 'Salin unggahan publik lokal ke bucket S3 (folder public/)';

    public function handle(): int
    {
        $source = Storage::build(['driver' => 'local', 'root' => storage_path('app/public'), 'throw' => true]);
        $target = Storage::build(config('filesystems.disks.s3') + ['root' => 'public', 'retain_visibility' => false, 'throw' => true]);
        $files = array_values(array_filter($source->allFiles(), fn ($f) => ! str_starts_with(basename($f), '.')));
        $this->info(count($files).' file di storage/app/public'.($this->option('dry-run') ? ' (dry run)' : ''));

        [$copied, $skipped, $failed] = [0, 0, 0];
        $bar = $this->output->createProgressBar(count($files));
        foreach ($files as $path) {
            $bar->advance();
            try {
                if ($target->exists($path) && $target->size($path) === $source->size($path)) {
                    $skipped++;

                    continue;
                }
                if (! $this->option('dry-run')) {
                    $target->writeStream($path, $source->readStream($path), ['ContentType' => $source->mimeType($path) ?: 'application/octet-stream']);
                }
                $copied++;
            } catch (Throwable $e) {
                $failed++;
                $this->newLine();
                $this->error("$path: ".mb_substr($e->getMessage(), 0, 160));
            }
        }
        $bar->finish();
        $this->newLine(2);
        $this->table(['Disalin', 'Sudah ada (dilewati)', 'Gagal'], [[$copied, $skipped, $failed]]);
        if (! $failed && ! $this->option('dry-run')) {
            $this->line('Selesai. Set PUBLIC_DISK_DRIVER=s3 di .env, lalu php artisan config:cache.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
