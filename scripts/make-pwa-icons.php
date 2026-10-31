<?php

/**
 * Bangun ikon PWA PNG dari tanda TapCard (sama dengan public/favicon.svg): huruf T + tiga busur sinyal champagne
 * di atas onyx. Digambar 8× lalu diperkecil agar tepi halus. Jalankan: php scripts/make-pwa-icons.php
 *   icon-192/512   — sudut membulat (rx 5/24)
 *   maskable-512   — persegi penuh, tanda diperkecil ke zona aman 80% (Android memotong bentuknya sendiri)
 *   apple-touch    — 180 px persegi penuh (iOS membulatkan sendiri)
 */
const ONYX = [0x0D, 0x0D, 0x0E];
const CHAMPAGNE = [0xC9, 0xA9, 0x6E];

function icon(int $size, bool $rounded, float $scale): GdImage
{
    $S = $size * 8;
    $u = $S / 24; // satuan viewBox 24
    $im = imagecreatetruecolor($S, $S);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    $bg = imagecolorallocate($im, ...ONYX);
    $fg = imagecolorallocate($im, ...CHAMPAGNE);

    if ($rounded) {
        $r = (int) (5 * $u);
        imagefilledrectangle($im, $r, 0, $S - $r, $S, $bg);
        imagefilledrectangle($im, 0, $r, $S, $S - $r, $bg);
        foreach ([[$r, $r], [$S - $r, $r], [$r, $S - $r], [$S - $r, $S - $r]] as [$cx, $cy]) {
            imagefilledellipse($im, $cx, $cy, 2 * $r, 2 * $r, $bg);
        }
    } else {
        imagefilledrectangle($im, 0, 0, $S, $S, $bg);
    }

    // koordinat viewBox → piksel, diskalakan di sekitar pusat (12,12)
    $p = fn (float $x, float $y) => [(12 + ($x - 12) * $scale) * $u, (12 + ($y - 12) * $scale) * $u];
    $w = 1.6 * $scale * $u; // tebal garis
    $dot = fn (array $c) => imagefilledellipse($im, (int) round($c[0]), (int) round($c[1]), (int) round($w), (int) round($w), $fg);
    $line = function (array $a, array $b) use ($im, $fg, $w, $dot) {
        // garis tebal = persegi panjang (tegak lurus arah garis) + ujung bulat
        $len = hypot($b[0] - $a[0], $b[1] - $a[1]);
        [$nx, $ny] = [-($b[1] - $a[1]) / $len * $w / 2, ($b[0] - $a[0]) / $len * $w / 2];
        imagefilledpolygon($im, array_map('intval', [$a[0] + $nx, $a[1] + $ny, $b[0] + $nx, $b[1] + $ny, $b[0] - $nx, $b[1] - $ny, $a[0] - $nx, $a[1] - $ny]), $fg);
        $dot($a);
        $dot($b);
    };
    $line($p(6.5, 9.5), $p(13.5, 9.5));
    $line($p(10, 9.5), $p(10, 17.5));

    // busur sinyal: sektor seperempat lingkaran (atas → kanan) luar warna, dalam latar; dari terluar ke dalam
    [$cx, $cy] = $p(15.5, 9.5);
    foreach ([4.9, 3.1, 1.3] as $r) {
        $outer = ($r * $scale * $u + $w / 2) * 2;
        $inner = ($r * $scale * $u - $w / 2) * 2;
        imagefilledarc($im, (int) $cx, (int) $cy, (int) $outer, (int) $outer, 270, 360, $fg, IMG_ARC_PIE);
        imagefilledarc($im, (int) $cx, (int) $cy, (int) $inner, (int) $inner, 269, 361, $bg, IMG_ARC_PIE);
        $rr = $r * $scale * $u;
        $dot([$cx, $cy - $rr]);
        $dot([$cx + $rr, $cy]);
    }

    $out = imagecreatetruecolor($size, $size);
    imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagecopyresampled($out, $im, 0, 0, 0, 0, $size, $size, $S, $S);

    return $out;
}

$dir = __DIR__.'/../public/icons';
is_dir($dir) || mkdir($dir, 0755, true);
foreach ([
    'icon-192.png' => [192, true, 1.0],
    'icon-512.png' => [512, true, 1.0],
    'maskable-512.png' => [512, false, 0.78],
    'apple-touch-icon.png' => [180, false, 0.9],
] as $name => [$size, $rounded, $scale]) {
    imagepng(icon($size, $rounded, $scale), "$dir/$name", 9);
    echo "public/icons/$name\n";
}
