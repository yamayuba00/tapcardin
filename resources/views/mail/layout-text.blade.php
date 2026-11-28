@php use App\Support\Mail\EmailContent; use App\Support\Mail\MailLayout; $f = $m['footer']; @endphp
{!! $m['title'] !!}
[{!! MailLayout::tone($m['tone'] ?? 'default')['label'] !!}]

@if (! empty($m['name']))
Halo {!! $m['name'] !!},

@endif
@foreach ($m['lines'] as $line)
{!! EmailContent::plain($line) !!}

@endforeach
@if (! empty($m['facts']))
RINGKASAN
@foreach ($m['facts'] as [$label, $value])
- {!! $label !!}: {!! $value !!}
@endforeach

@endif
@if (! empty($m['items']))
ITEM
@foreach ($m['items'] as $item)
- {!! $item['label'] !!} x{!! $item['qty'] !!}{!! $item['amount'] ? ' — '.$item['amount'] : '' !!}
@endforeach

@endif
@if (! empty($m['action']))
{!! $m['action']['label'] !!}: {!! $m['action']['url'] !!}

@endif
@foreach ($m['notes'] ?? [] as $note)
{!! $note !!}

@endforeach
--
@if ($f['recipient'] || $f['workspace'])
Dikirim untuk {!! $f['recipient'] ?? '' !!}{!! $f['recipient'] && $f['workspace'] ? ' · ' : '' !!}{!! $f['workspace'] ? 'workspace '.$f['workspace'] : '' !!}
@endif
{!! $f['reason'] !!}
Pusat bantuan: {!! $f['help_url'] !!}
@if ($f['settings_url'])
{!! $f['settings_label'] !!}: {!! $f['settings_url'] !!}
@endif
Dikirim otomatis oleh TapCard dari {!! $f['sender'] !!} — mohon tidak membalas email ini.
