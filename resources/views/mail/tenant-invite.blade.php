<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>Undangan bergabung ke {{ $workspace }}</title>
</head>
<body style="margin:0;padding:0;background:#F4F1EA;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#3A352E;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">Anda diundang bergabung ke {{ $workspace }} di TapCard.</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F4F1EA;">
<tr><td align="center" style="padding:32px 16px;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;">
    <tr><td style="padding:0 4px 16px;font-size:18px;font-weight:700;letter-spacing:.02em;color:#141210;">
      Tap<span style="color:#7D6230;">Card</span>
    </td></tr>
    <tr><td style="background:#FFFFFF;border:1px solid #D8D1C2;border-top:4px solid #C9A96E;border-radius:8px;padding:28px 28px 24px;">
      <h1 style="margin:0 0 16px;font-size:20px;line-height:1.35;color:#141210;">Anda diundang bergabung ke {{ $workspace }}</h1>
      <p style="margin:0 0 14px;font-size:15px;line-height:1.6;">
        {{ $inviter ? $inviter.' mengundang Anda' : 'Anda diundang' }} sebagai <strong>{{ $role }}</strong> di workspace perusahaan {{ $workspace }}.
      </p>
      <p style="margin:0 0 14px;font-size:15px;line-height:1.6;">
        Klik tombol di bawah, konfirmasi email Anda, lalu buat kata sandi untuk masuk.
        Tautan berlaku sampai {{ $expiresAt->timezone('Asia/Jakarta')->translatedFormat('d M Y, H.i') }} WIB dan hanya bisa dipakai sekali.
      </p>
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0 6px;">
        <tr><td style="border-radius:6px;background:#141210;">
          <a href="{{ $url }}" style="display:inline-block;padding:13px 22px;font-size:15px;font-weight:600;color:#FFFFFF;text-decoration:none;border-radius:6px;">Terima undangan</a>
        </td></tr>
      </table>
      <p style="margin:14px 0 0;font-size:12px;line-height:1.5;color:#7A7266;">Tombol tidak berfungsi? Salin tautan ini ke browser:<br><a href="{{ $url }}" style="color:#7D6230;word-break:break-all;">{{ $url }}</a></p>
      <p style="margin:14px 0 0;font-size:12px;line-height:1.5;color:#7A7266;">Jika Anda tidak merasa diundang, abaikan email ini.</p>
    </td></tr>
    <tr><td style="padding:18px 4px 0;font-size:12px;line-height:1.6;color:#7A7266;">
      Email ini dikirim otomatis oleh TapCard — mohon tidak membalas.
    </td></tr>
  </table>
</td></tr>
</table>
</body>
</html>
