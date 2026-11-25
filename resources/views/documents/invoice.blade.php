{{-- Tagihan/kuitansi PDF (dompdf). Mengikuti halaman detail tagihan: warna tema gelap, logo, dan susunan yang sama. --}}
@php($rp = fn (int $n) => \App\Support\Billing\InvoicePdf::rp($n))
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>{{ $number }}</title>
<style>
  @page { margin: 0; }
  html, body { background: #0D0D0E; margin: 0; padding: 0; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #F3EFE6; padding: 36px 40px; }
  .card { background: #151517; border: 1px solid #2C2C30; padding: 28px 30px; }
  .muted { color: #9B978F; }
  .label { font-size: 8px; text-transform: uppercase; letter-spacing: 1px; color: #9B978F; }
  .mono { font-family: DejaVu Sans Mono, monospace; }
  .num { text-align: right; white-space: nowrap; font-family: DejaVu Sans Mono, monospace; }
  .right { text-align: right; }
  table { width: 100%; border-collapse: collapse; }
  td { vertical-align: top; }
  .badge { display: inline-block; padding: 3px 10px; border: 1px solid; border-radius: 10px; font-size: 9px; }
  .b-ok { border-color: #7FB8A4; color: #7FB8A4; }
  .b-warn { border-color: #E0A458; color: #E0A458; }
  .b-danger { border-color: #E07A6A; color: #E07A6A; }
  .b-muted { border-color: #9B978F; color: #9B978F; }
  .items th { font-size: 8px; text-transform: uppercase; letter-spacing: 1px; color: #9B978F; text-align: left; padding: 8px 0; border-bottom: 1px solid #9B978F; }
  .items td { padding: 11px 0; border-bottom: 1px solid #2C2C30; }
  .note { display: block; font-size: 9px; color: #7FB8A4; margin-top: 2px; }
  .totals td { padding: 4px 0; }
  .grand td { padding-top: 10px; font-size: 15px; font-weight: bold; color: #F3EFE6; }
  .grand .amount { color: #C9A96E; }
  .foot { margin-top: 26px; font-size: 9px; color: #9B978F; }
  .brand { font-size: 20px; font-weight: bold; letter-spacing: -0.2px; color: #F3EFE6; }
</style>
</head>
<body>
<div class="card">

  <table>
    <tr>
      <td style="width: 60%;">
        <div>
          <img src="{{ $logo }}" width="34" height="34" alt="" style="width: 34px; height: 34px; vertical-align: middle; margin-right: 8px;">
          <span class="brand">TapCard</span>
        </div>
        <div class="muted" style="margin-top: 4px;">TapCard — kartu nama NFC &amp; bio link</div>
      </td>
      <td class="right">
        <div class="label">{{ $title }}</div>
        <div class="mono" style="font-size: 14px; margin-top: 3px;">{{ $number }}</div>
        <div style="margin-top: 6px;"><span class="badge b-{{ $tone }}">{{ $status }}</span></div>
      </td>
    </tr>
  </table>

  <table style="margin-top: 22px;">
    <tr>
      <td style="width: 40%;">
        <div class="label">Ditagihkan kepada</div>
        <div style="margin-top: 4px;">{{ $bill['name'] }}</div>
        <div class="muted">{{ $bill['email'] }}</div>
        <div class="muted">{{ $tenant }}</div>
      </td>
      <td style="width: 30%;">
        <div class="label">Tanggal terbit</div>
        <div class="mono" style="margin-top: 4px;">{{ $issued }}</div>
      </td>
      <td>
        <div class="label">{{ $dateLabel }}</div>
        <div class="mono" style="margin-top: 4px;">{{ $dateValue }}</div>
      </td>
    </tr>
  </table>

  <table class="items" style="margin-top: 26px;">
    <thead>
      <tr>
        <th>Deskripsi</th>
        <th class="right" style="width: 50px;">Jml</th>
        <th class="right" style="width: 140px;">Harga</th>
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

  <table class="totals" style="margin-top: 12px;">
    <tr><td class="muted">Subtotal</td><td class="num">{{ $rp($subtotal) }}</td></tr>
    @if ($discount > 0)
      <tr><td class="muted">Diskon</td><td class="num">−{{ $rp($discount) }}</td></tr>
    @endif
    @if ($tax > 0)
      <tr><td class="muted">PPN</td><td class="num">{{ $rp($tax) }}</td></tr>
    @endif
    <tr class="grand"><td>Total</td><td class="num amount">{{ $rp($total) }}</td></tr>
  </table>

  <div class="foot">Pertanyaan tentang tagihan ini? Balas email notifikasi atau hubungi support TapCard dengan menyebut nomor {{ $number }}.</div>
</div>
</body>
</html>
