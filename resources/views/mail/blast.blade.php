{{-- Email blast promo: tata letak tabel + gaya inline (Gmail/Outlook). Isi sudah dirender aman oleh BlastMarkdown. --}}
<!DOCTYPE html>
<html lang="id" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;-webkit-text-size-adjust:100%;">
@if ($preheader)
<div style="display:none;max-height:0;max-width:0;overflow:hidden;opacity:0;mso-hide:all;font-size:1px;line-height:1px;color:#f3f4f6;">{{ $preheader }}&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;</div>
@endif
<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="background:#f3f4f6;">
<tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="max-width:600px;">
<tr><td style="padding:0 8px 16px;">
@if ($logoUrl)
<img src="{{ $logoUrl }}" alt="TapCard" height="28" style="display:block;height:28px;width:auto;border:0;">
@else
<span style="font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:20px;font-weight:800;letter-spacing:-0.02em;color:#0d1117;">TapCard</span>
@endif
</td></tr>
<tr><td style="background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;padding:32px 28px;">
{!! $bodyHtml !!}
</td></tr>
<tr><td style="padding:20px 8px 0;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:12px;line-height:1.6;color:#6b7280;">
Anda menerima email ini karena terdaftar sebagai pengguna TapCard.<br>
<a href="{{ $unsubscribeUrl }}" target="_blank" rel="noopener" style="color:#6b7280;text-decoration:underline;">Berhenti berlangganan email promo</a>
@if ($address)
<br>{{ $address }}
@endif
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
