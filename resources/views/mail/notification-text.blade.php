@php use App\Support\Mail\EmailContent; @endphp
{!! $mail['title'] !!}

Halo {!! $name !!},

@foreach ($mail['lines'] as $line)
{!! EmailContent::plain($line) !!}

@endforeach
@if ($mail['action'])
{!! $mail['action']['label'] !!}: {!! $mail['action']['url'] !!}

@endif
--
Email ini dikirim otomatis oleh TapCard — mohon tidak membalas.
@if (! in_array($mail['type'] ?? '', EmailContent::ALWAYS, true))
Atur email apa saja yang Anda terima di Pengaturan → Notifikasi.
@endif
