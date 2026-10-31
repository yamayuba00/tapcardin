<?php

namespace App\Support\Csv;

use Generator;

/**
 * Pembaca CSV tanpa paket (TRD §10.7, `fgetcsv`). Menangani BOM UTF-8, file ekspor Excel
 * Indonesia (pemisah `;`) atau tab, dan teks Windows-1252 yang bukan UTF-8 valid.
 */
final class CsvReader
{
    /** Kolom tujuan impor → kata kunci header yang dikenali untuk pemetaan otomatis. */
    public const FIELDS = [
        'name' => ['nama', 'name', 'nama lengkap', 'full name', 'kontak'],
        'email' => ['email', 'e-mail', 'surel', 'mail'],
        'phone' => ['telepon', 'phone', 'hp', 'no hp', 'nomor', 'whatsapp', 'wa', 'mobile', 'telp'],
        'company' => ['perusahaan', 'company', 'organisasi', 'instansi', 'kantor'],
        'job_title' => ['jabatan', 'title', 'job title', 'posisi', 'position'],
        'note' => ['catatan', 'note', 'notes', 'keterangan'],
        'tags' => ['tag', 'tags', 'label'],
    ];

    public static function delimiter(string $path): string
    {
        $line = (string) fgets($h = fopen($path, 'r'));
        fclose($h);
        $counts = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
    }

    /** @return Generator<int, list<string>> baris (indeks 0 = header) */
    public static function rows(string $path, string $delimiter): Generator
    {
        $h = fopen($path, 'r');
        $i = 0;
        try {
            while (($row = fgetcsv($h, null, $delimiter, '"', '')) !== false) {
                if ($row === [null]) {
                    continue; // baris kosong
                }
                $row = array_map(fn ($v) => self::utf8((string) $v), $row);
                if ($i === 0 && isset($row[0])) {
                    $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]);
                }
                yield $i++ => array_map('trim', $row);
            }
        } finally {
            fclose($h);
        }
    }

    /** Tebak pemetaan kolom dari nama header. @return array<string,int> field → indeks kolom */
    public static function guessMapping(array $headers): array
    {
        $norm = array_map(fn ($h) => strtolower(trim(preg_replace('/[_\-.]+/', ' ', $h))), $headers);
        $map = [];
        foreach (self::FIELDS as $field => $words) {
            foreach ($norm as $i => $h) {
                if (in_array($i, $map, true)) {
                    continue;
                }
                if (in_array($h, $words, true) || collect($words)->contains(fn ($w) => str_contains($h, $w) && strlen($w) > 3)) {
                    $map[$field] = $i;
                    break;
                }
            }
        }

        return $map;
    }

    private static function utf8(string $v): string
    {
        return mb_check_encoding($v, 'UTF-8') ? $v : mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
    }
}
