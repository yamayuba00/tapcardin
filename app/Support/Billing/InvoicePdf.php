<?php

namespace App\Support\Billing;

use App\Models\Invoice;
use Dompdf\Dompdf;

/** PDF tagihan di server (dompdf). HTML dibangun di sini; semua teks pengguna di-escape. */
final class InvoicePdf
{
    private const STATUS = [
        'paid' => 'Lunas', 'issued' => 'Belum dibayar', 'overdue' => 'Jatuh tempo', 'void' => 'Dibatalkan', 'draft' => 'Draf',
    ];

    public static function render(Invoice $invoice, string $tenantName): string
    {
        $dompdf = new Dompdf(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false, 'isHtml5ParserEnabled' => true]);
        $dompdf->loadHtml(self::html($invoice, $tenantName), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return $dompdf->output();
    }

    /** nama berkas aman dari nomor tagihan (mis. ORD/2026/11/000001 → ORD-2026-11-000001) */
    public static function filename(Invoice $invoice): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '-', $invoice->number).'.pdf';
    }

    private static function html(Invoice $invoice, string $tenantName): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $rp = fn (int $n) => ($n < 0 ? '-' : '').'Rp '.number_format(abs($n), 0, ',', '.');
        $snap = $invoice->billing_snapshot ?? [];
        $shipTo = implode(', ', array_filter((array) ($snap['ship_to'] ?? [])));
        $date = fn ($d) => $d ? $d->timezone('Asia/Jakarta')->format('d/m/Y H:i') : '—';
        $status = self::STATUS[$invoice->status] ?? $invoice->status;

        $rows = '';
        foreach ((array) $invoice->lines as $l) {
            $rows .= '<tr><td>'.$e($l['label'] ?? '')
                .(! empty($l['note']) ? '<br><span class="muted">'.$e($l['note']).'</span>' : '')
                .'</td><td class="num">'.$e($l['qty'] ?? 1).'</td><td class="num">'.$rp((int) ($l['amount'] ?? 0)).'</td></tr>';
        }
        $tax = (int) $invoice->tax > 0 ? '<tr><td colspan="2">PPN</td><td class="num">'.$rp((int) $invoice->tax).'</td></tr>' : '';
        $discount = (int) $invoice->discount > 0 ? '<tr><td colspan="2">Potongan</td><td class="num">-'.$rp((int) $invoice->discount).'</td></tr>' : '';
        $shipLine = $shipTo !== '' ? '<br>Kirim ke: '.$e($shipTo) : '';
        $paidAt = $invoice->paid_at ? '<p>Dibayar: '.$e($date($invoice->paid_at)).'</p>' : '';

        return <<<HTML
<!doctype html><html><head><meta charset="utf-8"><style>
body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
h1 { font-size: 18px; margin: 0 0 4px; }
.muted { color: #666; }
.head { width: 100%; border-bottom: 1px solid #ccc; padding-bottom: 10px; margin-bottom: 14px; }
.head td { vertical-align: top; }
.right { text-align: right; }
table.items { width: 100%; border-collapse: collapse; margin-top: 8px; }
table.items th, table.items td { border-bottom: 1px solid #e5e5e5; padding: 6px 4px; text-align: left; }
table.items th { font-size: 10px; text-transform: uppercase; color: #555; }
.num { text-align: right; white-space: nowrap; }
.total td { font-weight: bold; font-size: 13px; border-top: 2px solid #111; }
.badge { display: inline-block; padding: 2px 8px; border: 1px solid #111; border-radius: 10px; font-size: 10px; }
.foot { margin-top: 24px; font-size: 9px; color: #666; }
</style></head><body>
<table class="head"><tr>
  <td><h1>TapCard</h1><div class="muted">Tagihan {$e($tenantName)}</div></td>
  <td class="right"><strong>{$e($invoice->number)}</strong><br>Tanggal: {$e($date($invoice->issued_at))}<br><span class="badge">{$e($status)}</span></td>
</tr></table>
<table><tr>
  <td style="width:50%"><strong>Ditagihkan kepada</strong><br>{$e($snap['name'] ?? '')}<br>{$e($snap['email'] ?? '')}
    {$shipLine}</td>
  <td style="width:50%"><strong>Paket / keterangan</strong><br>{$e($invoice->type === 'order' ? 'Pesanan kartu cetak' : ($invoice->plan?->name ?? 'Layanan TapCard'))}
    {$paidAt}</td>
</tr></table>
<table class="items"><thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Jumlah</th></tr></thead><tbody>
{$rows}
</tbody><tfoot>
<tr><td colspan="2">Subtotal</td><td class="num">{$rp((int) $invoice->subtotal)}</td></tr>
{$discount}{$tax}
<tr class="total"><td colspan="2">Total</td><td class="num">{$rp((int) $invoice->total)}</td></tr>
</tfoot></table>
<p class="foot">Dokumen ini dibuat otomatis oleh TapCard. Jika ada perbedaan dengan riwayat tagihan di aplikasi, data di aplikasi yang berlaku.</p>
</body></html>
HTML;
    }
}
