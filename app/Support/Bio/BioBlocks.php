<?php

namespace App\Support\Bio;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Jenis blok bio link + validasi isi per jenis + bentuk publiknya. Yang disimpan hanya data mentah yang sudah
 * dirapikan; URL embed video dibangun di sini dari daftar penyedia yang diizinkan (tanpa HTML/iframe bebas).
 * Pengurai video harus sama dengan resources/js/lib/bioBlocks.js (preview editor).
 */
final class BioBlocks
{
    public const TYPES = ['save_contact', 'contacts', 'button', 'text', 'video', 'divider', 'form'];

    /** Jenis isian formulir (tanpa unggah file/gambar). */
    public const FIELD_TYPES = ['text', 'textarea', 'email', 'phone', 'number', 'select', 'multi', 'date'];

    public const MAX_FIELDS = 12;

    public const MAX_OPTIONS = 20;

    public const FORM_SUCCESS = 'Terima kasih! Pesan Anda sudah terkirim.';

    /** Jenis yang cukup satu per halaman. */
    public const SINGLE = ['save_contact' => 'Simpan kontak', 'contacts' => 'Kontak & tautan'];

    public const SAVE_LABEL = 'Simpan ke Kontak HP';

    public const MAX_PAGES = 3;

    public const MAX_BLOCKS = 30;

    /** Asal iframe yang boleh dimuat (dipakai juga oleh CSP frame-src). */
    public const EMBED_ORIGINS = ['https://www.youtube-nocookie.com', 'https://player.vimeo.com', 'https://www.tiktok.com', 'https://open.spotify.com'];

    /**
     * Rapikan & validasi isi satu blok.
     *
     * @throws ValidationException
     */
    public static function normalize(string $type, array $data, string $key): array
    {
        $fail = fn (string $field, string $msg) => throw ValidationException::withMessages(["$key.$field" => $msg]);
        $str = fn (string $field, int $max) => mb_substr(trim((string) ($data[$field] ?? '')), 0, $max);
        $pick = fn (string $field, array $allowed) => in_array($data[$field] ?? null, $allowed, true) ? $data[$field] : $allowed[0];

        return match ($type) {
            'save_contact' => ['label' => $str('label', 40) ?: self::SAVE_LABEL],
            'contacts' => [],
            'button' => (function () use ($str, $pick, $fail, $data) {
                $label = $str('label', 60);
                if ($label === '') {
                    $fail('label', 'Isi teks tombol.');
                }
                $url = self::link((string) ($data['url'] ?? '')) ?? $fail('url', 'Tautan tidak valid. Pakai alamat web (https://…), mailto:, atau tel:.');

                return ['label' => $label, 'url' => $url, 'style' => $pick('style', ['primary', 'secondary'])];
            })(),
            'text' => (function () use ($str, $pick, $fail) {
                $title = $str('title', 80);
                $body = $str('body', 1000);
                if ($title === '' && $body === '') {
                    $fail('body', 'Isi judul atau teks.');
                }

                return ['title' => $title, 'body' => $body, 'align' => $pick('align', ['left', 'center'])];
            })(),
            'video' => (function () use ($data, $fail) {
                $url = trim((string) ($data['url'] ?? ''));
                $v = self::video($url) ?? $fail('url', 'Tautan video belum didukung. Pakai YouTube, TikTok, Vimeo, atau Spotify.');

                return ['url' => mb_substr($url, 0, 500)] + $v;
            })(),
            'divider' => ['style' => $pick('style', ['line', 'space'])],
            'form' => self::form($data, $key),
        };
    }

    /**
     * Formulir buatan pemilik: teks tombol, judul, keterangan, pesan terima kasih, kontak wajib (nama + WhatsApp/email),
     * dan maks. 12 pertanyaan. ID pertanyaan stabil (dipakai sebagai kunci jawaban); yang baru diberi ID acak.
     */
    private static function form(array $data, string $key): array
    {
        $fail = fn (string $field, string $msg) => throw ValidationException::withMessages(["$key.$field" => $msg]);
        $str = fn (mixed $v, int $max) => mb_substr(trim((string) $v), 0, $max);
        $label = $str($data['label'] ?? '', 40);
        if ($label === '') {
            $fail('label', 'Isi teks tombol formulir.');
        }
        $fields = array_values(array_filter((array) ($data['fields'] ?? []), 'is_array'));
        if (count($fields) > self::MAX_FIELDS) {
            $fail('fields', 'Maksimal '.self::MAX_FIELDS.' pertanyaan per formulir.');
        }
        $out = [];
        $ids = [];
        foreach ($fields as $i => $f) {
            $type = in_array($f['type'] ?? null, self::FIELD_TYPES, true) ? $f['type'] : $fail("fields.$i.type", 'Jenis isian tidak dikenal.');
            $q = $str($f['label'] ?? '', 80);
            if ($q === '') {
                $fail("fields.$i.label", 'Isi pertanyaannya.');
            }
            $id = preg_match('/^[a-z0-9]{6,12}$/', (string) ($f['id'] ?? '')) && ! isset($ids[$f['id']]) ? $f['id'] : strtolower(Str::random(8));
            $ids[$id] = true;
            $field = ['id' => $id, 'type' => $type, 'label' => $q, 'required' => (bool) ($f['required'] ?? false)];
            if (in_array($type, ['select', 'multi'], true)) {
                $opts = array_values(array_unique(array_filter(array_map(fn ($o) => $str($o, 60), (array) ($f['options'] ?? [])), fn ($o) => $o !== '')));
                if (count($opts) < 2) {
                    $fail("fields.$i.options", 'Isi minimal 2 pilihan (satu per baris).');
                }
                if (count($opts) > self::MAX_OPTIONS) {
                    $fail("fields.$i.options", 'Maksimal '.self::MAX_OPTIONS.' pilihan.');
                }
                $field['options'] = $opts;
            }
            $hint = $str($f['placeholder'] ?? '', 80);
            if ($hint !== '' && ! in_array($type, ['select', 'multi', 'date'], true)) {
                $field['placeholder'] = $hint;
            }
            $out[] = $field;
        }

        return [
            'label' => $label,
            'style' => in_array($data['style'] ?? null, ['primary', 'secondary'], true) ? $data['style'] : 'primary',
            'title' => $str($data['title'] ?? '', 80) ?: $label,
            'description' => $str($data['description'] ?? '', 300),
            'success' => $str($data['success'] ?? '', 200) ?: self::FORM_SUCCESS,
            'contact' => in_array($data['contact'] ?? null, ['both', 'whatsapp', 'email'], true) ? $data['contact'] : 'both',
            'fields' => $out,
        ];
    }

    /** Isi halaman bio baru: tombol simpan kontak di atas, lalu daftar kontak. */
    public static function starter(): array
    {
        return [['type' => 'save_contact', 'data' => ['label' => self::SAVE_LABEL]], ['type' => 'contacts', 'data' => []]];
    }

    /** Tautan tombol: http(s) dengan host valid, mailto:, atau tel:. Tanpa skema → dianggap https://. */
    public static function link(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || mb_strlen($url) > 500) {
            return null;
        }
        if (preg_match('/^mailto:[^\s@]+@[^\s@]+\.[^\s@]+$/i', $url) || preg_match('/^tel:\+?[0-9][0-9 ()-]{4,20}$/i', $url)) {
            return $url;
        }
        if (! preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
            $url = 'https://'.$url;
        }
        $parts = parse_url($url);
        if (! $parts || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        $host = strtolower($parts['host'] ?? '');

        return str_contains($host, '.') && ! isset($parts['user']) ? $url : null;
    }

    /**
     * Kenali tautan video/audio dari penyedia yang diizinkan.
     *
     * @return array{provider:string, id:string, kind:string}|null
     */
    public static function video(string $url): ?array
    {
        $p = parse_url(trim($url));
        if (! $p || ! in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])) {
            return null;
        }
        $host = preg_replace('/^(www|m|music)\./', '', strtolower($p['host']));
        $path = $p['path'] ?? '/';
        parse_str($p['query'] ?? '', $q);
        $yt = fn (?string $id, string $kind = 'video') => is_string($id) && preg_match('/^[A-Za-z0-9_-]{11}$/', $id)
            ? ['provider' => 'youtube', 'id' => $id, 'kind' => $kind] : null;

        return match (true) {
            $host === 'youtu.be' => $yt(explode('/', trim($path, '/'))[0] ?? null),
            in_array($host, ['youtube.com', 'youtube-nocookie.com'], true) => match (true) {
                $path === '/watch' => $yt($q['v'] ?? null),
                (bool) preg_match('#^/shorts/([^/]+)#', $path, $m) => $yt($m[1], 'short'),
                (bool) preg_match('#^/(embed|live)/([^/]+)#', $path, $m) => $yt($m[2]),
                default => null,
            },
            $host === 'vimeo.com' => preg_match('#^/(?:.*/)?(\d{6,12})/?$#', $path, $m) ? ['provider' => 'vimeo', 'id' => $m[1], 'kind' => 'video'] : null,
            $host === 'player.vimeo.com' => preg_match('#^/video/(\d{6,12})/?$#', $path, $m) ? ['provider' => 'vimeo', 'id' => $m[1], 'kind' => 'video'] : null,
            $host === 'tiktok.com' => preg_match('#^/@[^/]+/video/(\d{10,25})/?$#', $path, $m) ? ['provider' => 'tiktok', 'id' => $m[1], 'kind' => 'video'] : null,
            $host === 'open.spotify.com' => preg_match('#^/(?:intl-[a-z-]+/)?(track|album|playlist|episode|show|artist)/([A-Za-z0-9]{22})/?$#', $path, $m)
                ? ['provider' => 'spotify', 'id' => $m[2], 'kind' => $m[1]] : null,
            default => null,
        };
    }

    /** URL iframe + ukuran untuk video yang sudah dikenali. */
    public static function embed(string $provider, string $id, string $kind): array
    {
        return match ($provider) {
            'youtube' => ['embed_url' => "https://www.youtube-nocookie.com/embed/$id", 'ratio' => $kind === 'short' ? '9/16' : '16/9', 'height' => null],
            'vimeo' => ['embed_url' => "https://player.vimeo.com/video/$id", 'ratio' => '16/9', 'height' => null],
            'tiktok' => ['embed_url' => "https://www.tiktok.com/embed/v2/$id", 'ratio' => null, 'height' => 740],
            'spotify' => ['embed_url' => "https://open.spotify.com/embed/$kind/$id", 'ratio' => null, 'height' => in_array($kind, ['track', 'episode'], true) ? 152 : 352],
        };
    }

    /** Bentuk publik satu blok (resolver & preview). */
    public static function present(string $id, string $type, array $data): array
    {
        return ['id' => $id, 'type' => $type] + match ($type) {
            'video' => ['provider' => $data['provider']] + self::embed($data['provider'], $data['id'], $data['kind']),
            default => $data,
        };
    }
}
