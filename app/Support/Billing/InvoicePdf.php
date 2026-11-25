<?php

namespace App\Support\Billing;

use App\Models\Invoice;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\Storage;

/**
 * Tagihan sebagai PDF. Data dibentuk di sini; tampilan HTML ada di resources/views/documents/invoice.blade.php.
 * Hasil disimpan di disk `documents` (S3 bila DOCUMENTS_DISK_DRIVER=s3). Nama berkas memuat hash isi tagihan,
 * sehingga tagihan yang berubah (mis. lunas) menghasilkan berkas baru dan berkas lama tetap utuh.
 */
final class InvoicePdf
{
    private const STATUS = [
        'paid' => ['Lunas', 'ok'], 'issued' => ['Belum dibayar', 'warn'], 'overdue' => ['Terlambat', 'danger'],
        'void' => ['Dibatalkan', 'muted'], 'draft' => ['Draf', 'muted'],
    ];

    /** @return array{path: string, filename: string} */
    public static function store(Invoice $invoice, string $tenantName): array
    {
        $data = self::data($invoice, $tenantName);
        $path = 'invoices/'.$invoice->public_id.'/'.substr(sha1(json_encode($data)), 0, 16).'.pdf';
        $disk = Storage::disk('documents');

        if (! $disk->exists($path)) {
            $dompdf = new Dompdf(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false, 'isHtml5ParserEnabled' => true]);
            $dompdf->loadHtml(view('documents.invoice', $data)->render(), 'UTF-8');
            $dompdf->setPaper('A4');
            $dompdf->render();
            $disk->put($path, $dompdf->output());
        }

        return ['path' => $path, 'filename' => self::filename($invoice)];
    }

    /** Data tampilan tagihan; sumber yang sama dengan halaman detail tagihan. */
    public static function data(Invoice $invoice, string $tenantName): array
    {
        $snap = $invoice->billing_snapshot ?? [];
        [$statusLabel, $tone] = self::STATUS[$invoice->status] ?? [$invoice->status, 'muted'];
        $paid = $invoice->status === 'paid';
        $date = fn ($d) => $d ? $d->timezone('Asia/Jakarta')->format('j M Y, H.i') : '—';

        return [
            'number' => $invoice->number,
            'title' => $paid ? 'Kuitansi' : 'Invoice',
            'status' => $statusLabel,
            'tone' => $tone,
            'bill' => ['name' => $snap['name'] ?? '', 'email' => $snap['email'] ?? ''],
            'tenant' => $tenantName,
            'issued' => $date($invoice->issued_at),
            'dateLabel' => $paid ? 'Dibayar' : 'Berlaku sampai',
            'dateValue' => $date($paid ? $invoice->paid_at : $invoice->due_at),
            'lines' => collect((array) $invoice->lines)->map(fn ($l) => [
                'label' => (string) ($l['label'] ?? ''),
                'note' => (string) ($l['note'] ?? ''),
                'qty' => (int) ($l['qty'] ?? 1),
                'amount' => (int) ($l['amount'] ?? 0),
            ])->values()->all(),
            'subtotal' => (int) $invoice->subtotal,
            'discount' => (int) $invoice->discount,
            'tax' => (int) $invoice->tax,
            'total' => (int) $invoice->total,
        ];
    }

    /** format rupiah seperti halaman tagihan (Rp 10.000.000) */
    public static function rp(int $n): string
    {
        return ($n < 0 ? '−' : '').'Rp '.number_format(abs($n), 0, ',', '.');
    }

    /** nama berkas aman dari nomor tagihan (mis. ORD/2026/11/000001 → ORD-2026-11-000001.pdf) */
    public static function filename(Invoice $invoice): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '-', $invoice->number).'.pdf';
    }
}
