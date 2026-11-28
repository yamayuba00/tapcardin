{{--
  Tata letak bersama semua email transaksional (notifikasi & undangan). Data: lihat App\Support\Mail\MailLayout.
  Kompatibilitas: tabel + gaya inline (Gmail/Outlook), tombol VML untuk Outlook desktop, gaya <style> hanya
  penyempurnaan (mode gelap Apple Mail/Outlook.com, ponsel). Teks pengguna selalu di-escape ({{ }} / EmailContent::html).
--}}
@php
    use App\Support\Mail\EmailContent;
    use App\Support\Mail\MailLayout;
    $tone = MailLayout::tone($m['tone'] ?? 'default');
    $f = $m['footer'];
    $font = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
@endphp
<!doctype html>
<html lang="id" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="x-apple-disable-message-reformatting">
<meta name="format-detection" content="telephone=no, date=no, address=no, email=no, url=no">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title>{{ $m['subject'] }}</title>
<!--[if mso]>
<noscript><xml><o:OfficeDocumentSettings><o:AllowPNG/><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript>
<style>table,td,h1,p,a,span{font-family:'Segoe UI',Arial,sans-serif !important;}</style>
<![endif]-->
<style>
  :root { color-scheme: light dark; supported-color-schemes: light dark; }
  body { margin:0 !important; padding:0 !important; width:100% !important; -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%; }
  table { border-collapse:collapse; mso-table-lspace:0pt; mso-table-rspace:0pt; }
  img { border:0; outline:none; text-decoration:none; -ms-interpolation-mode:bicubic; }
  a[x-apple-data-detectors] { color:inherit !important; text-decoration:none !important; }
  @media only screen and (max-width:620px) {
    .tc-pad { padding-left:20px !important; padding-right:20px !important; }
    .tc-outer { padding:20px 10px !important; }
    .tc-h1 { font-size:21px !important; }
    .tc-btn a { display:block !important; text-align:center !important; }
    .tc-btn, .tc-btn-wrap { width:100% !important; }
  }
  @media (prefers-color-scheme: dark) {
    .tc-bg { background:#0D0D0E !important; }
    .tc-card { background:#151517 !important; border-color:#2C2C30 !important; }
    .tc-box { background:#1F1F22 !important; border-color:#2C2C30 !important; }
    .tc-row { border-color:#2C2C30 !important; }
    .tc-ink, .tc-ink strong, .tc-word { color:#F3EFE6 !important; }
    .tc-muted { color:#9B978F !important; }
    .tc-link { color:#E2CFA6 !important; }
    .tc-gold { color:#C9A96E !important; }
    .tc-badge { background:#1F1F22 !important; color:{{ $tone['dark'] }} !important; border-color:{{ $tone['dark'] }} !important; }
    .tc-btn { background:#C9A96E !important; }
    .tc-btn a { color:#0D0D0E !important; }
  }
  /* Outlook.com / Outlook app mode gelap */
  [data-ogsc] .tc-ink, [data-ogsc] .tc-ink strong, [data-ogsc] .tc-word { color:#F3EFE6 !important; }
  [data-ogsc] .tc-muted { color:#9B978F !important; }
  [data-ogsc] .tc-link { color:#E2CFA6 !important; }
  [data-ogsb] .tc-bg { background:#0D0D0E !important; }
  [data-ogsb] .tc-card { background:#151517 !important; }
  [data-ogsb] .tc-box { background:#1F1F22 !important; }
</style>
</head>
<body class="tc-bg" style="margin:0;padding:0;background:#F3EFE6;font-family:{{ $font }};color:#3A352E;">
{{-- baris pratinjau kotak masuk; spasi tak terlihat agar isi email tidak ikut tampil di pratinjau --}}
<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">{{ $m['preheader'] }}&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="tc-bg" style="background:#F3EFE6;">
<tr><td align="center" class="tc-outer" style="padding:32px 16px;">
<!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" align="center"><tr><td><![endif]-->
<div style="max-width:600px;margin:0 auto;">
  {{-- logo: tanda (PNG 2x) + wordmark teks — tetap terbaca bila gambar dimatikan --}}
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
    <tr><td style="padding:0 4px 18px;">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
        <td width="36" valign="middle" style="width:36px;"><img src="{{ MailLayout::logoUrl() }}" width="36" height="36" alt="TapCard" style="display:block;width:36px;height:36px;border-radius:8px;font-family:{{ $font }};font-size:11px;font-weight:700;color:#7D6230;"></td>
        <td valign="middle" style="padding-left:10px;font-family:{{ $font }};font-size:20px;line-height:24px;font-weight:700;letter-spacing:.01em;color:#141210;" class="tc-word">Tap<span class="tc-gold" style="color:#7D6230;">Card</span></td>
      </tr></table>
    </td></tr>
  </table>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="tc-card" style="background:#FFFFFF;border:1px solid #E2DBCC;border-radius:12px;border-collapse:separate;">
    {{-- garis aksen sesuai nada --}}
    <tr><td height="5" style="height:5px;line-height:5px;font-size:0;background:{{ $tone['bar'] }};border-radius:12px 12px 0 0;">&nbsp;</td></tr>
    <tr><td class="tc-pad tc-ink" style="padding:28px 32px 8px;font-family:{{ $font }};color:#3A352E;">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;"><tr>
        <td class="tc-badge" style="background:{{ $tone['soft'] }};border:1px solid {{ $tone['bar'] }};border-radius:999px;padding:3px 10px;font-family:{{ $font }};font-size:12px;line-height:16px;font-weight:700;color:{{ $tone['ink'] }};">{{ $tone['label'] }}</td>
      </tr></table>
      <h1 class="tc-h1 tc-ink" style="margin:14px 0 16px;font-family:{{ $font }};font-size:23px;line-height:1.3;font-weight:700;color:#141210;">{{ $m['title'] }}</h1>
      @if (! empty($m['name']))
        <p style="margin:0 0 14px;font-size:15px;line-height:1.65;">Halo {{ $m['name'] }},</p>
      @endif
      @foreach ($m['lines'] as $line)
        <p style="margin:0 0 14px;font-size:15px;line-height:1.65;">{!! EmailContent::html($line) !!}</p>
      @endforeach
    </td></tr>

    @if (! empty($m['facts']))
    <tr><td class="tc-pad" style="padding:6px 32px 4px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="tc-box" style="background:#FAF7F1;border:1px solid #E2DBCC;border-radius:10px;border-collapse:separate;">
        <tr><td colspan="2" class="tc-muted" style="padding:12px 16px 6px;font-family:{{ $font }};font-size:11px;line-height:14px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#7A7266;">Ringkasan</td></tr>
        @foreach ($m['facts'] as [$label, $value])
        <tr>
          <td valign="top" width="42%" class="tc-muted tc-row" style="padding:9px 8px 9px 16px;border-top:1px solid #EAE4D6;font-family:{{ $font }};font-size:13px;line-height:1.45;color:#6F675B;">{{ $label }}</td>
          <td valign="top" class="tc-ink tc-row" style="padding:9px 16px 9px 8px;border-top:1px solid #EAE4D6;font-family:{{ $font }};font-size:14px;line-height:1.45;font-weight:600;color:#141210;word-break:break-word;">{{ $value }}</td>
        </tr>
        @endforeach
      </table>
    </td></tr>
    @endif

    @if (! empty($m['items']))
    <tr><td class="tc-pad" style="padding:12px 32px 4px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">
        <tr>
          <th align="left" class="tc-muted" style="padding:0 0 8px;font-family:{{ $font }};font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#7A7266;">Item</th>
          <th align="center" width="48" class="tc-muted" style="padding:0 0 8px;font-family:{{ $font }};font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#7A7266;">Qty</th>
          <th align="right" width="110" class="tc-muted" style="padding:0 0 8px;font-family:{{ $font }};font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#7A7266;">Jumlah</th>
        </tr>
        @foreach ($m['items'] as $item)
        <tr>
          <td class="tc-ink tc-row" style="padding:8px 8px 8px 0;border-top:1px solid #EAE4D6;font-family:{{ $font }};font-size:14px;line-height:1.45;color:#141210;">{{ $item['label'] }}</td>
          <td align="center" class="tc-ink tc-row" style="padding:8px 4px;border-top:1px solid #EAE4D6;font-family:{{ $font }};font-size:14px;color:#141210;">{{ $item['qty'] }}</td>
          <td align="right" class="tc-ink tc-row" style="padding:8px 0;border-top:1px solid #EAE4D6;font-family:{{ $font }};font-size:14px;color:#141210;white-space:nowrap;">{{ $item['amount'] ?? '-' }}</td>
        </tr>
        @endforeach
      </table>
    </td></tr>
    @endif

    <tr><td class="tc-pad tc-ink" style="padding:18px 32px 28px;font-family:{{ $font }};color:#3A352E;">
      @if (! empty($m['action']))
        @php($w = MailLayout::buttonWidth($m['action']['label']))
        {{-- tombol anti-gagal: VML untuk Outlook desktop, tabel + tautan untuk klien lain --}}
        <!--[if mso]>
        <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $m['action']['url'] }}" style="height:48px;v-text-anchor:middle;width:{{ $w }}px;" arcsize="17%" stroke="f" fillcolor="#141210">
          <w:anchorlock/>
          <center style="color:#FFFFFF;font-family:'Segoe UI',Arial,sans-serif;font-size:16px;font-weight:bold;">{{ $m['action']['label'] }}</center>
        </v:roundrect>
        <![endif]-->
        <!--[if !mso]><!-- -->
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" class="tc-btn-wrap" style="margin:4px 0 0;">
          <tr><td class="tc-btn" style="border-radius:8px;background:#141210;">
            <a href="{{ $m['action']['url'] }}" target="_blank" style="display:inline-block;padding:14px 28px;font-family:{{ $font }};font-size:16px;line-height:20px;font-weight:700;color:#FFFFFF;text-decoration:none;border-radius:8px;">{{ $m['action']['label'] }} &rarr;</a>
          </td></tr>
        </table>
        <!--<![endif]-->
        <p class="tc-muted" style="margin:16px 0 0;font-size:12px;line-height:1.55;color:#7A7266;">Tombol tidak berfungsi? Buka tautan ini:<br><a href="{{ $m['action']['url'] }}" target="_blank" class="tc-link" style="color:#7D6230;text-decoration:underline;word-break:break-all;">{{ $m['action']['url'] }}</a></p>
      @endif
      @foreach ($m['notes'] ?? [] as $note)
        <p class="tc-muted" style="margin:12px 0 0;font-size:12px;line-height:1.55;color:#7A7266;">{{ $note }}</p>
      @endforeach
    </td></tr>
  </table>

  {{-- footer --}}
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
    <tr><td class="tc-muted" style="padding:20px 8px 0;font-family:{{ $font }};font-size:12px;line-height:1.65;color:#7A7266;">
      @if ($f['recipient'] || $f['workspace'])
        <p style="margin:0 0 6px;">Dikirim untuk {{ $f['recipient'] ?? '' }}{{ $f['recipient'] && $f['workspace'] ? ' · ' : '' }}{{ $f['workspace'] ? 'workspace '.$f['workspace'] : '' }}</p>
      @endif
      <p style="margin:0 0 6px;">{{ $f['reason'] }}</p>
      <p style="margin:0 0 6px;">
        <a href="{{ $f['help_url'] }}" target="_blank" class="tc-link" style="color:#7D6230;text-decoration:underline;">Pusat bantuan</a>
        @if ($f['settings_url'])
          &nbsp;·&nbsp; <a href="{{ $f['settings_url'] }}" target="_blank" class="tc-link" style="color:#7D6230;text-decoration:underline;">{{ $f['settings_label'] }}</a>
        @endif
      </p>
      <p style="margin:0;">Dikirim otomatis oleh TapCard dari {{ $f['sender'] }} — mohon tidak membalas email ini.</p>
    </td></tr>
  </table>
</div>
<!--[if mso]></td></tr></table><![endif]-->
</td></tr>
</table>
</body>
</html>
