<?php

namespace App\Support\Contacts;

use App\Models\ContactType;
use Illuminate\Support\Facades\Cache;

/** Registry jenis kontak dari tabel `contact_types` (di-cache sebagai array, bukan objek). */
final class ContactTypes
{
    private const CACHE = 'contact_types:v1';

    /** @return array<string, array{key:string, group:string, group_name:string, label:string, input:string, action:string, vcard:string, icon:string, url_template:?string, placeholder:?string, is_active:bool}> */
    public static function all(): array
    {
        return Cache::remember(self::CACHE, 3600, fn () => ContactType::with('group')
            ->join('contact_type_groups as g', 'g.id', '=', 'contact_types.group_id')
            ->orderBy('g.sort')->orderBy('contact_types.sort')->select('contact_types.*')->get()
            ->mapWithKeys(fn (ContactType $t) => [$t->key => [
                'key' => $t->key, 'group' => $t->group->key, 'group_name' => $t->group->name, 'label' => $t->label,
                'input' => $t->input, 'action' => $t->action, 'vcard' => $t->vcard, 'icon' => $t->icon,
                'url_template' => $t->url_template, 'placeholder' => $t->placeholder, 'is_active' => $t->is_active,
            ]])->all());
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** @return list<string> */
    public static function activeKeys(): array
    {
        return array_keys(array_filter(self::all(), fn ($t) => $t['is_active']));
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE);
    }
}
