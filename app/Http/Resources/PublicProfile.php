<?php

namespace App\Http\Resources;

use App\Actions\Cards\CardView;
use App\Support\Contacts\ContactTypes;
use App\Support\Contacts\ContactValue;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

/** Bentuk JSON profil publik (P-01) — dipakai resolver dan preview editor profil. */
final class PublicProfile
{
    public static function from(CardView $view, bool $preview = false, ?string $event = null): array
    {
        $p = $view->profile;
        $base = config('tapcard.resolver_url').'/c/'.$view->token;
        $link = fn (?array $f) => $f ? ($preview ? null : "$base/l/{$f['id']}") : null;

        $wa = $view->primary('whatsapp');
        $tel = $view->primary('phone');
        $mail = $view->primary('email');

        return [
            'token' => $view->token,
            'display_name' => $p['display_name'],
            'title' => $p['title'],
            'company' => $p['company'],
            'bio' => $p['bio'],
            'avatar_url' => $p['avatar_path'] ? Storage::disk('public')->url($p['avatar_path']) : null,
            'theme' => $p['theme'],
            'locale' => $p['locale'],
            'indexable' => (bool) $p['is_indexable'],
            'event' => $event,
            'brand' => $view->brand ? [
                'name' => $view->tenantName,
                'logo_url' => ($view->brand['logo_path'] ?? null) ? Storage::disk('public')->url($view->brand['logo_path']) : null,
            ] : null,
            'quick' => array_filter([
                'whatsapp' => $link($wa),
                'phone' => $link($tel) ?? ($wa && ! $preview ? 'tel:'.$wa['value'] : null),
                'email' => $link($mail),
            ]),
            // semua kontak selain yang sudah tampil sebagai tombol cepat (WA/telepon/email utama)
            'links' => array_values(array_filter(array_map(function ($f) use ($link, $wa, $tel, $mail) {
                $type = ContactTypes::get($f['type']);
                if (! $type || in_array($f['id'], array_filter([$wa['id'] ?? null, $tel['id'] ?? null, $mail['id'] ?? null]), true)) {
                    return null;
                }
                $meta = $f['meta'] ?? [];
                $opens = ContactValue::href($type, $f['value'], $meta) !== null && $type['action'] !== 'image';

                return [
                    'id' => $f['id'],
                    'type' => $f['type'],
                    'group' => $type['group'],
                    'icon' => $type['icon'],
                    'action' => $type['action'],
                    'label' => $f['label'] ?: $type['label'],
                    'display' => ContactValue::display($type, $f['value'], $meta),
                    'host' => $type['input'] === 'url' ? parse_url($f['value'], PHP_URL_HOST) : null,
                    'url' => $opens ? $link($f) : null, // lewat /c/{token}/l/{id} agar klik terhitung
                    'copy' => ContactValue::copyText($type, $f['value']),
                    'image' => $type['action'] === 'image' ? Storage::disk('public')->url($f['value']) : null,
                ];
            }, $view->fields))),
            'vcard_url' => $preview ? null : "$base/vcard",
            'exchange' => $p['show_exchange_form'] ? [
                'url' => $preview ? null : config('tapcard.resolver_url')."/api/c/{$view->token}/connect",
                // waktu render terenkripsi: kiriman < 2 detik dianggap bot (TRD §11)
                't' => $preview ? null : Crypt::encryptString((string) time()),
            ] : null,
        ];
    }
}
