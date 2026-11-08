@php
    use App\Support\Mail\EmailContent;
    // garis aksen di atas kartu mengikuti nada pesan
    $bar = ['ok' => '#2F7D4F', 'warn' => '#B7791F', 'danger' => '#B23B3B'][$mail['tone']] ?? '#C9A96E';
@endphp
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>{{ $mail['subject'] }}</title>
</head>
<body style="margin:0;padding:0;background:#F4F1EA;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#3A352E;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">{{ EmailContent::plain($mail['lines'][0] ?? $mail['title']) }}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F4F1EA;">
<tr><td align="center" style="padding:32px 16px;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;">
    <tr><td style="padding:0 4px 16px;font-size:18px;font-weight:700;letter-spacing:.02em;color:#141210;">
      Tap<span style="color:#7D6230;">Card</span>
    </td></tr>
    <tr><td style="background:#FFFFFF;border:1px solid #D8D1C2;border-top:4px solid {{ $bar }};border-radius:8px;padding:28px 28px 24px;">
      <h1 style="margin:0 0 16px;font-size:20px;line-height:1.35;color:#141210;">{{ $mail['title'] }}</h1>
      <p style="margin:0 0 14px;font-size:15px;line-height:1.6;">Halo {{ $name }},</p>
      @foreach ($mail['lines'] as $line)
        <p style="margin:0 0 14px;font-size:15px;line-height:1.6;">{!! EmailContent::html($line) !!}</p>
      @endforeach
      @if ($mail['action'])
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0 6px;">
          <tr><td style="border-radius:6px;background:#141210;">
            <a href="{{ $mail['action']['url'] }}" style="display:inline-block;padding:13px 22px;font-size:15px;font-weight:600;color:#FFFFFF;text-decoration:none;border-radius:6px;">{{ $mail['action']['label'] }}</a>
          </td></tr>
        </table>
        <p style="margin:14px 0 0;font-size:12px;line-height:1.5;color:#7A7266;">Tombol tidak berfungsi? Salin tautan ini ke browser:<br><a href="{{ $mail['action']['url'] }}" style="color:#7D6230;word-break:break-all;">{{ $mail['action']['url'] }}</a></p>
      @endif
    </td></tr>
    <tr><td style="padding:18px 4px 0;font-size:12px;line-height:1.6;color:#7A7266;">
      Email ini dikirim otomatis oleh TapCard — mohon tidak membalas.
      @if (! in_array($mail['type'] ?? '', EmailContent::ALWAYS, true))
        Atur email apa saja yang Anda terima di <strong>Pengaturan → Notifikasi</strong>.
      @endif
    </td></tr>
  </table>
</td></tr>
</table>
</body>
</html>
