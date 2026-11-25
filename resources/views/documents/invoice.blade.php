{{-- Tagihan/kuitansi PDF (dompdf). Susunan dan kata-kata mengikuti halaman detail tagihan di aplikasi. --}}
@php($rp = fn (int $n) => \App\Support\Billing\InvoicePdf::rp($n))
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>{{ $number }}</title>
<style>
  @page { margin: 36px 40px; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
  .muted { color: #6b6b6b; }
  .ok { color: #1f7a4d; }
  .warn { color: #a86400; }
  .danger { color: #b3261e; }
  .label { font-size: 9px; text-transform: uppercase; letter-spacing: 1px; color: #6b6b6b; }
  .mono { font-family: DejaVu Sans Mono, monospace; }
  table { width: 100%; border-collapse: collapse; }
  .head td { vertical-align: top; }
  .right { text-align: right; }
  .badge { display: inline-block; padding: 2px 9px; border: 1px solid #111; border-radius: 10px; font-size: 9px; }
  .meta td { vertical-align: top; padding-top: 14px; }
  .items { margin-top: 18px; }
  .items th { font-size: 9px; text-transform: uppercase; letter-spacing: 1px; color: #6b6b6b; text-align: left; padding: 6px 0; border-bottom: 1px solid #999; }
  .items td { padding: 9px 0; border-bottom: 1px solid #e5e5e5; }
  .items .num { text-align: right; white-space: nowrap; font-family: DejaVu Sans Mono, monospace; }
  .note { display: block; font-size: 9px; color: #1f7a4d; }
  .totals td { padding: 4px 0; }
  .grand td { padding-top: 10px; font-size: 14px; font-weight: bold; border-top: 1px solid #111; }
  .foot { margin-top: 28px; font-size: 9px; color: #6b6b6b; }
</style>
</head>
<body>
<table class="head">
  <tr>
    <td>
      <div style="font-size: 20px; font-weight: bold;">TapCard</div>
      <div class="muted">TapCard — kartu nama NFC &amp; bio link</div>
    </td>
    <td class="right">
      <div class="label">{{ $title }}</div>
      <div class="mono" style="font-size: 13px;">{{ $number }}</div>
      <div style="margin-top: 4px;"><span class="badge {{ $tone }}">{{ $status }}</span></div>
    </td>
  </tr>
</table>

<table class="meta">
  <tr>
    <td style="width: 40%;">
      <div class="label">Ditagihkan kepada</div>
      <div>{{ $bill['name'] }}</div>
      <div class="muted">{{ $bill['email'] }}</div>
      <div class="muted">{{ $tenant }}</div>
    </td>
    <td style="width: 30%;">
      <div class="label">Tanggal terbit</div>
      <div class="mono">{{ $issued }}</div>
    </td>
    <td>
      <div class="label">{{ $dateLabel }}</div>
      <div class="mono">{{ $dateValue }}</div>
    </td>
  </tr>
</table>

<table class="items">
  <thead>
    <tr>
      <th>Deskripsi</th>
      <th class="right" style="width: 50px;">Jml</th>
      <th class="right" style="width: 130px;">Harga</th>
    </tr>
  </thead>
  <tbody>
    @foreach ($lines as $line)
      <tr>
        <td>
          {{ $line['label'] }}
          @if ($line['note'] !== '')
            <span class="note">{{ $line['note'] }}</span>
          @endif
        </td>
        <td class="num">{{ $line['qty'] }}</td>
        <td class="num">{{ $rp($line['amount']) }}</td>
      </tr>
    @endforeach
  </tbody>
</table>

<table class="totals" style="margin-top: 10px;">
  <tr><td class="muted">Subtotal</td><td class="num mono right">{{ $rp($subtotal) }}</td></tr>
  @if ($discount > 0)
    <tr><td class="muted">Diskon</td><td class="mono right">−{{ $rp($discount) }}</td></tr>
  @endif
  @if ($tax > 0)
    <tr><td class="muted">PPN</td><td class="mono right">{{ $rp($tax) }}</td></tr>
  @endif
  <tr class="grand"><td>Total</td><td class="mono right">{{ $rp($total) }}</td></tr>
</table>

<div class="foot">Pertanyaan tentang tagihan ini? Balas email notifikasi atau hubungi support TapCard dengan menyebut nomor {{ $number }}.</div>
</body>
</html>
