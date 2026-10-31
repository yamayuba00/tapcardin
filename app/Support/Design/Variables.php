<?php

namespace App\Support\Design;

use App\Models\Profile;
use App\Models\User;

/**
 * Variabel teks desain: {{name}} {{title}} {{company}} {{phone}} {{email}} {{website}} (BSD §5)
 * + brand kit {{brand}} {{tagline}} {{brand_website}}.
 */
final class Variables
{
    public const KEYS = ['name', 'title', 'company', 'phone', 'email', 'website', 'brand', 'tagline', 'brand_website'];

    public const SAMPLE = [
        'name' => 'Nama Lengkap', 'title' => 'Jabatan', 'company' => 'Perusahaan',
        'phone' => '+62 812 3456 7890', 'email' => 'nama@email.com', 'website' => 'website.com',
        'brand' => 'Nama Merek', 'tagline' => 'Tagline merek Anda', 'brand_website' => 'merek.com',
    ];

    /** Variabel dari brand kit (BrandKit::toKit). */
    public static function forKit(?array $kit): array
    {
        return $kit ? array_filter([
            'brand' => $kit['display_name'] ?: $kit['name'], 'tagline' => $kit['tagline'], 'brand_website' => $kit['website'],
        ]) : [];
    }

    public static function fill(string $text, array $vars): string
    {
        return preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', fn ($m) => (string) ($vars[$m[1]] ?? self::SAMPLE[$m[1]] ?? $m[0]), $text);
    }

    /** Nilai dari profil pertama pengguna (untuk preview Studio); kosong → contoh. */
    public static function forUser(User $user, ?Profile $profile): array
    {
        if (! $profile) {
            return ['name' => $user->name] + self::SAMPLE;
        }
        $fields = $profile->fields;
        $first = fn (string ...$types) => $fields->first(fn ($f) => in_array($f->type, $types, true) && $f->is_primary)?->value
            ?? $fields->first(fn ($f) => in_array($f->type, $types, true))?->value;

        return array_filter([
            'name' => $profile->display_name,
            'title' => $profile->title,
            'company' => $profile->company,
            'phone' => $first('phone', 'whatsapp'),
            'email' => $first('email'),
            'website' => ($u = $first('url')) ? preg_replace('~^https?://(www\.)?~', '', rtrim($u, '/')) : null,
        ]) + self::SAMPLE;
    }
}
