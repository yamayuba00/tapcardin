<?php

namespace App\Jobs;

use App\Actions\Leads\CreateConnection;
use App\Enums\LeadSource;
use App\Exceptions\ApiError;
use App\Models\LeadImport;
use App\Support\Csv\CsvReader;
use App\Support\Notify;
use App\Tenancy\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Antrean `leads` (TRD §10.7): impor CSV dengan dedup yang sama dengan input manual (CreateConnection).
 * Progres disimpan tiap 500 baris; ringkasan (dibuat, digabung, gagal + alasan) dikirim sebagai notifikasi.
 */
class ImportLeadsCsv implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public const MAX_ROWS = 10000;

    public const MAX_ERRORS = 50;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $tenantId, public int $importId)
    {
        $this->onQueue('leads');
    }

    public function handle(CreateConnection $create): void
    {
        $this->enterTenant();
        $import = LeadImport::find($this->importId);
        if (! $import || $import->status !== 'queued') {
            return;
        }
        $import->update(['status' => 'processing']);
        $map = $import->mapping;
        $col = fn (array $row, string $field) => isset($map[$field]) ? ($row[$map[$field]] ?? '') : '';
        $counts = ['created_count' => 0, 'merged_count' => 0, 'failed_count' => 0];
        $errors = [];
        $fail = function (int $line, string $reason) use (&$counts, &$errors) {
            $counts['failed_count']++;
            if (count($errors) < self::MAX_ERRORS) {
                $errors[] = ['row' => $line, 'reason' => $reason];
            }
        };

        try {
            foreach (CsvReader::rows(Storage::disk('local')->path($import->path), $import->delimiter) as $i => $row) {
                if ($i === 0) {
                    continue; // header
                }
                $line = $i + 1; // nomor baris seperti di spreadsheet
                $name = $col($row, 'name');
                if ($name === '') {
                    $fail($line, 'Nama kosong.');
                } else {
                    $email = $col($row, 'email');
                    if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $fail($line, "Email \"$email\" tidak valid.");
                    } else {
                        try {
                            $r = $create->handle([
                                'name' => $name, 'email' => $email ?: null, 'phone' => $col($row, 'phone') ?: null,
                                'company' => $col($row, 'company') ?: null, 'job_title' => $col($row, 'job_title') ?: null,
                                'note' => $col($row, 'note') ?: null,
                                'tags' => preg_split('/\s*[,;|]\s*/', $col($row, 'tags'), -1, PREG_SPLIT_NO_EMPTY),
                            ], LeadSource::Import, $import->user_id, ['quiet' => true]);
                            $counts[$r['duplicate'] ? 'merged_count' : 'created_count']++;
                        } catch (ApiError $e) {
                            $fail($line, $e->getMessage()); // mis. batas koneksi paket tercapai
                        }
                    }
                }
                if ($i % 500 === 0) {
                    $import->update($counts + ['errors' => $errors]);
                }
            }
            $import->update($counts + ['errors' => $errors, 'status' => 'done', 'finished_at' => now()]);
        } catch (Throwable $e) {
            $import->update($counts + ['errors' => $errors, 'status' => 'failed', 'finished_at' => now()]);
            throw $e;
        } finally {
            Storage::disk('local')->delete($import->path);
        }

        Notify::user($import->user_id, $import->tenant_id, 'import_done', [
            'id' => $import->public_id, 'filename' => $import->filename,
            'created' => $counts['created_count'], 'merged' => $counts['merged_count'], 'failed' => $counts['failed_count'],
        ]);
    }
}
