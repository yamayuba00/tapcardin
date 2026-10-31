<?php

namespace App\Support\Design;

use App\Models\Card;
use App\Models\Design;
use App\Models\Profile;
use App\Support\Qr\QrEncoder;
use App\Support\Qr\SvgRenderer;

/**
 * Tampilan kartu untuk layar & unduhan gambar: desain kartu (desain Studio yang dipilih, atau template bawaan)
 * diisi data bio kartu itu sendiri, dengan QR asli kartu. Kartu digital tidak punya chip, jadi elemen NFC
 * (ikon tap, zona TAP) dan kode pendek cetak dibuang — QR yang menjadi jalan masuknya.
 * Teks yang variabelnya kosong (mis. belum ada email) disembunyikan, bukan menampilkan contoh/placeholder.
 */
final class CardVisual
{
    public const DEFAULT_TEMPLATE = 'onyx';

    /** Elemen sistem yang hanya bermakna untuk kartu ber-chip. */
    public const CHIP_ONLY = ['sys-nfc', 'sys-tapzone', 'sys-tapicon', 'sys-shortcode'];

    public static function for(Card $card): array
    {
        $design = $card->design_id ? Design::find($card->design_id) : null; // soft delete → kembali ke template
        $orientation = $design?->orientation ?? 'landscape';
        $sides = $design
            ? ['front' => $design->front_json, 'back' => $design->back_json]
            : TemplateLibrary::make(self::DEFAULT_TEMPLATE, new CardGeometry($orientation));
        $kit = $design?->brandKit?->toKit();

        $profile = $card->profile_id ? Profile::withoutGlobalScopes()->with('fields')->find($card->profile_id) : null;
        $vars = Variables::forKit($kit) + self::profileVars($profile);
        $qr = QrEncoder::encode($card->qrUrl());

        return [
            'orientation' => $orientation,
            'design' => $design ? ['id' => $design->public_id, 'name' => $design->name] : null,
            'front' => self::prepare($sides['front'], $vars, $card->isDigital()),
            'back' => self::prepare($sides['back'], $vars, $card->isDigital()),
            'kit' => $kit,
            'vars' => $vars,
            'qr' => ['modules' => $qr->size, 'path' => SvgRenderer::path($qr)],
        ];
    }

    /** Variabel dari bio kartu ini (bukan contoh): nilai kosong tetap kosong. */
    public static function profileVars(?Profile $profile): array
    {
        if (! $profile) {
            return [];
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
        ], fn ($v) => $v !== null && $v !== '');
    }

    private static function prepare(array $side, array $vars, bool $digital): array
    {
        $layers = [];
        foreach ($side['layers'] ?? [] as $l) {
            if ($digital && in_array($l['id'] ?? null, self::CHIP_ONLY, true)) {
                continue;
            }
            if (($l['type'] ?? null) === 'text' && str_contains($l['text'] ?? '', '{{')) {
                $l['text'] = preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', fn ($m) => (string) ($vars[$m[1]] ?? ''), $l['text']);
                if (trim(preg_replace('/[\s·•|,\-–—]+/u', '', $l['text'])) === '') {
                    continue; // semua variabelnya kosong → jangan tampilkan teks kosong/pemisah saja
                }
            }
            $layers[] = $l;
        }
        $side['layers'] = $layers;

        return $side;
    }
}
