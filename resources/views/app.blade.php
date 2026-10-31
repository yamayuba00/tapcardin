<!doctype html>
<html lang="{{ $meta['lang'] ?? 'id' }}" @if (isset($meta['theme'])) data-theme="{{ $meta['theme'] }}" @endif>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <meta property="csp-nonce" nonce="{{ Vite::cspNonce() }}">
  <title>{{ $meta['title'] ?? 'TapCard' }}</title>
  @if ($meta)
    {{-- Pratinjau tautan (WhatsApp/iMessage) tanpa menunggu JS --}}
    <meta name="description" content="{{ $meta['description'] }}">
    <meta property="og:title" content="{{ $meta['title'] }}">
    <meta property="og:description" content="{{ $meta['description'] }}">
    <meta property="og:type" content="profile">
    @if ($meta['image'])<meta property="og:image" content="{{ $meta['image'] }}">@endif
  @endif
  <link rel="icon" href="/favicon.svg" type="image/svg+xml">
  <link rel="manifest" href="/manifest.webmanifest">
  <link rel="preload" href="/fonts/syne-700.woff2" as="font" type="font/woff2" crossorigin>
  @vite(['resources/css/app.css', 'resources/js/main.js'])
</head>
<body class="bg-bg text-fg antialiased">
  {{-- Shell SPA: tidak ada UI di sini. Semua tampilan dirender Vue. --}}
  <div id="app" data-config='@json($config)'></div>
</body>
</html>
