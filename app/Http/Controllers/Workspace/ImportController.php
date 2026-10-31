<?php

namespace App\Http\Controllers\Workspace;

use App\Exceptions\ApiError;
use App\Jobs\ImportLeadsCsv;
use App\Models\LeadImport;
use App\Support\Csv\CsvReader;
use App\Support\Http\Table;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** LD-10 — impor CSV: unggah → pratinjau 20 baris + pemetaan kolom → proses di antrean → ringkasan. */
class ImportController extends WorkspaceController
{
    public function index(Request $request): JsonResponse
    {
        $this->access()->authorize('leads.edit.*');
        $q = LeadImport::where('user_id', $this->user()->id);

        return Table::json(Table::paginate($q, $request, ['created_at' => 'created_at', 'filename' => 'filename'], '-created_at', ['filename'], 10),
            fn (LeadImport $i) => self::present($i), $request);
    }

    /** POST /api/leads/imports (multipart `file`) — simpan file, kembalikan header, 20 baris contoh, tebakan pemetaan. */
    public function upload(Request $request): JsonResponse
    {
        $this->access()->authorize('leads.edit.*');
        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:csv,txt']],
            ['file.mimes' => 'Unggah file .csv (ekspor dari Excel / Google Sheets: File → Download → CSV).']);
        $file = $request->file('file');
        $path = $file->storeAs("imports/t{$this->tenant()->id}", Str::random(32).'.csv', 'local');
        $abs = Storage::disk('local')->path($path);

        $delimiter = CsvReader::delimiter($abs);
        $headers = [];
        $sample = [];
        $total = 0;
        foreach (CsvReader::rows($abs, $delimiter) as $i => $row) {
            if ($i === 0) {
                $headers = $row;
            } else {
                $total++;
                if ($total <= 20) {
                    $sample[] = $row;
                }
            }
        }
        if (! $headers || $total === 0) {
            Storage::disk('local')->delete($path);
            throw new ApiError('import_empty', 'File kosong atau hanya berisi header.');
        }
        if ($total > ImportLeadsCsv::MAX_ROWS) {
            Storage::disk('local')->delete($path);
            throw new ApiError('import_too_large', 'Maksimal '.number_format(ImportLeadsCsv::MAX_ROWS, 0, ',', '.').' baris per file. Pecah file Anda menjadi beberapa bagian.');
        }

        $import = LeadImport::create([
            'user_id' => $this->user()->id, 'filename' => mb_substr($file->getClientOriginalName(), 0, 160), 'path' => $path,
            'delimiter' => $delimiter, 'headers' => $headers, 'mapping' => CsvReader::guessMapping($headers), 'total_rows' => $total,
        ]);

        return response()->json(['data' => self::present($import) + ['sample' => $sample, 'fields' => array_keys(CsvReader::FIELDS)]], 201);
    }

    public function show(LeadImport $import): JsonResponse
    {
        $this->mine($import);

        return response()->json(['data' => self::present($import)]);
    }

    /** POST /api/leads/imports/{id}/start {mapping: {field: kolom}} */
    public function start(Request $request, LeadImport $import): JsonResponse
    {
        $this->mine($import);
        abort_unless($import->status === 'uploaded', 409, 'Impor ini sudah diproses.');
        $cols = count($import->headers);
        $data = $request->validate([
            'mapping' => ['required', 'array'],
            'mapping.name' => ['required', 'integer', 'min:0', 'max:'.($cols - 1)],
            'mapping.*' => ['nullable', 'integer', 'min:0', 'max:'.($cols - 1)],
        ], ['mapping.name.required' => 'Pilih kolom untuk Nama.']);
        $mapping = array_filter(
            array_intersect_key($data['mapping'], CsvReader::FIELDS),
            fn ($v) => $v !== null,
        );
        if (count($mapping) !== count(array_unique($mapping))) {
            throw new ApiError('mapping_duplicate', 'Satu kolom hanya boleh dipetakan ke satu isian.');
        }

        $import->update(['mapping' => $mapping, 'status' => 'queued']);
        ImportLeadsCsv::dispatch($this->tenant()->id, $import->id);

        return response()->json(['data' => self::present($import->fresh())]);
    }

    public function destroy(LeadImport $import): JsonResponse
    {
        $this->mine($import);
        abort_unless(in_array($import->status, ['uploaded', 'done', 'failed'], true), 409);
        Storage::disk('local')->delete($import->path);
        $import->delete();

        return response()->json(null, 204);
    }

    private function mine(LeadImport $i): void
    {
        abort_unless($i->user_id === $this->user()->id, 404);
    }

    public static function present(LeadImport $i): array
    {
        return [
            'id' => $i->public_id, 'filename' => $i->filename, 'status' => $i->status,
            'headers' => $i->headers, 'mapping' => (object) $i->mapping, 'total_rows' => $i->total_rows,
            'created' => $i->created_count, 'merged' => $i->merged_count, 'failed' => $i->failed_count,
            'errors' => $i->errors, 'created_at' => $i->created_at?->toIso8601String(), 'finished_at' => $i->finished_at?->toIso8601String(),
        ];
    }
}
