<!doctype html>
<html lang="{{ $meta['lang'] ?? 'id' }}" @if (isset($meta['theme'])) data-theme="{{ $meta['theme'] }}" @endif>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="csrf-token" content="{{ request()->hasSession() ? csrf_token() : '' }}">
  <meta property="csp-nonce" nonce="{{ Vite::cspNonce() }}">
  <title>{{ $meta['title'] ?? 'TapCard' }}</title>
  @if ($meta)
    {{-- Pratinjau tautan (WhatsApp/iMessage) & mesin pencari tanpa menunggu JS --}}
    <meta name="description" content="{{ $meta['description'] }}">
    @isset($meta['canonical'])<link rel="canonical" href="{{ $meta['canonical'] }}">
    <meta property="og:url" content="{{ $meta['canonical'] }}">@endisset
    <meta property="og:title" content="{{ $meta['title'] }}">
    <meta property="og:description" content="{{ $meta['description'] }}">
    <meta property="og:type" content="{{ $meta['og_type'] ?? 'profile' }}">
    <meta property="og:site_name" content="TapCard">
    <meta property="og:locale" content="id_ID">
    @if ($meta['image'])<meta property="og:image" content="{{ $meta['image'] }}">
    @isset($meta['image_size'])<meta property="og:image:width" content="{{ $meta['image_size'][0] }}"><meta property="og:image:height" content="{{ $meta['image_size'][1] }}">@endisset
    <meta property="og:image:alt" content="{{ $meta['title'] }}">@endif
    <meta name="twitter:card" content="{{ $meta['image'] ? 'summary_large_image' : 'summary' }}">
    <meta name="twitter:title" content="{{ $meta['title'] }}">
    <meta name="twitter:description" content="{{ $meta['description'] }}">
    @if ($meta['image'])<meta name="twitter:image" content="{{ $meta['image'] }}">@endif
    @isset($meta['theme_color'])<meta name="theme-color" content="{{ $meta['theme_color'] }}">@endisset
    @isset($meta['json_ld'])<script type="application/ld+json" nonce="{{ Vite::cspNonce() }}">{!! json_encode($meta['json_ld'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>@endisset
  @endif
  <link rel="icon" href="{{ $favicon ?? '/favicon.svg' }}" type="image/svg+xml">
  @if ($pwa ?? null)
    {{-- Aplikasi / panel bisa dipasang ke layar utama (PWA); service worker didaftarkan oleh composables/usePwa.js --}}
    <link rel="manifest" href="{{ $pwa[0] }}">
    <link rel="apple-touch-icon" href="{{ $pwa[2] }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ $pwa[1] }}">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  @endif
  <link rel="preload" href="/fonts/syne-700.woff2" as="font" type="font/woff2" crossorigin>
  @php($heading = ['IBM Plex Sans' => 'ibm-plex-sans-600', 'IBM Plex Mono' => 'ibm-plex-mono-500'][$meta['font'] ?? ''] ?? null)
  @if ($heading)<link rel="preload" href="/fonts/{{ $heading }}.woff2" as="font" type="font/woff2" crossorigin>@endif
  @if (isset($meta['font']))<link rel="preload" href="/fonts/ibm-plex-sans-400.woff2" as="font" type="font/woff2" crossorigin>@endif
  @vite(['resources/css/app.css', 'resources/js/main.js'])
</head>
<body class="bg-bg text-fg antialiased">
  {{-- Shell SPA: UI dirender Vue. Beranda marketing berisi HTML hasil render server (SSR) yang diganti Vue saat mount. --}}
  <div id="app" data-config='@json($config)'>{!! $ssr ?? '' !!}</div>
</body>
</html>
