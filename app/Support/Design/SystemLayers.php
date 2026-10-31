<?php

namespace App\Support\Design;

/**
 * Elemen wajib & terkunci (DBD §8.3 + permintaan pemilik produk):
 *   depan    — ikon NFC (petunjuk "tempel di sini")
 *   belakang — zona TAP, QR code + kode pendek, wordmark TapCard
 * Selalu dibangun ulang di server dari geometri + warna latar, jadi tidak bisa diubah/dihapus dari klien.
 * Warna menyesuaikan kontras latar; QR selalu modul gelap di atas pelat terang (DBD §8.1).
 */
final class SystemLayers
{
    public const PREFIX = 'sys-';

    /** @return list<array> */
    public static function front(CardGeometry $g, string $background): array
    {
        $ink = Palette::inkOn($background);
        $size = 5.0;

        return [
            ['id' => 'sys-nfc', 'type' => 'icon', 'name' => 'nfc', 'locked' => true,
                // pojok kanan atas: di luar area slot lanyard (slot selalu di tengah atas)
                'x' => round($g->width() - CardGeometry::SAFE - $size, 2), 'y' => CardGeometry::SAFE,
                'size' => $size, 'color' => $ink],
        ];
    }

    /** @return list<array> */
    public static function back(CardGeometry $g, string $background): array
    {
        $ink = Palette::inkOn($background);
        $plateBg = Palette::hex('bone-0');
        $qrInk = Palette::hex('ink-950');
        $W = $g->width();
        $H = $g->height();

        if ($g->portrait()) {
            $plate = 25.0;
            $px = ($W - $plate) / 2;
            $py = max($g->contentTop() + 18.0, 30.0);
            $tap = ['cx' => $W / 2, 'cy' => $g->contentTop() + 8.5, 'r' => 7.0];
            $short = ['x' => $W / 2, 'y' => $py + $plate + 4.2, 'align' => 'center'];
            $brand = ['x' => $W / 2, 'y' => $H - CardGeometry::SAFE - 1.2, 'align' => 'center'];
        } else {
            $plate = 23.0;
            $px = $W - CardGeometry::SAFE - $plate - 1.0;
            $py = max(($H - $plate) / 2 - 2.0, $g->contentTop());
            $tap = ['cx' => 17.0, 'cy' => $H / 2 - 2.0, 'r' => 9.0];
            $short = ['x' => $px + $plate / 2, 'y' => $py + $plate + 4.2, 'align' => 'center'];
            $brand = ['x' => CardGeometry::SAFE + 0.5, 'y' => $H - CardGeometry::SAFE - 1.2, 'align' => 'left'];
        }

        return [
            // zona TAP: lingkaran garis + ikon NFC di tengah
            ['id' => 'sys-tapzone', 'type' => 'circle', 'locked' => true, 'cx' => $tap['cx'], 'cy' => $tap['cy'], 'r' => $tap['r'], 'stroke' => $ink, 'strokeWidth' => 0.35],
            ['id' => 'sys-tapicon', 'type' => 'icon', 'name' => 'nfc', 'locked' => true,
                'x' => round($tap['cx'] - $tap['r'] * 0.45, 2), 'y' => round($tap['cy'] - $tap['r'] * 0.45, 2), 'size' => round($tap['r'] * 0.9, 2), 'color' => $ink],
            // QR ≥ 15 mm + quiet zone, modul gelap di atas pelat terang solid
            ['id' => 'sys-qr', 'type' => 'qr', 'locked' => true, 'x' => round($px, 2), 'y' => round($py, 2), 'size' => $plate, 'fg' => $qrInk, 'bg' => $plateBg, 'rx' => 1.6],
            ['id' => 'sys-shortcode', 'type' => 'shortcode', 'locked' => true, 'x' => round($short['x'], 2), 'y' => round($short['y'], 2),
                'size' => 6.5, 'color' => $ink, 'align' => $short['align']],
            ['id' => 'sys-brand', 'type' => 'brand', 'locked' => true, 'x' => round($brand['x'], 2), 'y' => round($brand['y'], 2),
                'size' => 7.0, 'color' => $ink, 'align' => $brand['align']],
        ];
    }

    /** Kotak pembatas elemen sistem (untuk validasi tumpang-tindih). @return list<array{x:float,y:float,w:float,h:float,id:string}> */
    public static function boxes(array $layers): array
    {
        $out = [];
        foreach ($layers as $l) {
            $box = match ($l['type']) {
                'qr' => [$l['x'], $l['y'], $l['size'], $l['size']],
                'circle' => [$l['cx'] - $l['r'], $l['cy'] - $l['r'], $l['r'] * 2, $l['r'] * 2],
                'icon' => [$l['x'], $l['y'], $l['size'], $l['size']],
                default => null,
            };
            if ($box) {
                $out[] = ['id' => $l['id'], 'x' => $box[0], 'y' => $box[1], 'w' => $box[2], 'h' => $box[3]];
            }
        }

        return $out;
    }
}
