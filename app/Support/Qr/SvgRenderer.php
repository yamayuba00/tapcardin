<?php

namespace App\Support\Qr;

/**
 * Satu <path> per gambar, quiet zone 4 modul. Warna memakai `currentColor` agar diatur
 * token CSS (DBD §3.4 — tidak ada hex di luar tokens.css).
 * Logo TapCard di tengah hanya bila data muat di level H (tahan ±30% rusak); modul di bawah logo dikosongkan.
 */
final class SvgRenderer
{
    /** Tanda TapCard (sama dengan public/favicon.svg), viewBox 24×24: kotak + huruf T + gelombang NFC. */
    public const MARK = 'M6.5 9.5h7M10 9.5v8M15.5 8.2a1.6 1.6 0 0 1 1.3 1.3M15.5 6.4a3.4 3.4 0 0 1 3.1 3.1M15.5 4.6a5.2 5.2 0 0 1 4.9 4.9';

    /** Sisi area logo terhadap sisi QR (±8% luas — jauh di bawah batas koreksi level H). */
    private const LOGO_RATIO = 0.27;

    /**
     * Data QR untuk digambar di klien: modul, path modul gelap, dan kotak logo (koordinat modul) atau null.
     *
     * @return array{modules:int, path:string, logo:array{x:int,y:int,size:int}|null}
     */
    public static function payload(string $data, bool $logo = true): array
    {
        $withLogo = $logo && QrEncoder::fits(strlen($data), 'H');
        $m = QrEncoder::encode($data, level: $withLogo ? 'H' : 'M');
        $box = $withLogo ? self::logoBox($m->size) : null;

        return ['modules' => $m->size, 'path' => self::path($m, 0, $box), 'logo' => $box];
    }

    public static function render(Matrix $m, int $quiet = 4, ?string $label = 'Kode QR', ?string $background = null, ?array $logo = null): string
    {
        $size = $m->size + 2 * $quiet;
        $d = self::path($m, $quiet, $logo);
        $bg = $background !== null
            ? sprintf('<rect width="%d" height="%d" fill="%s"/>', $size, $size, htmlspecialchars($background))
            : '';
        $aria = $label !== null ? sprintf(' role="img" aria-label="%s"', htmlspecialchars($label)) : ' aria-hidden="true"';
        $mark = $logo ? self::mark($logo['x'] + $quiet, $logo['y'] + $quiet, $logo['size']) : '';

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d" shape-rendering="crispEdges"%2$s>%3$s<path fill="currentColor" d="%4$s"/>%5$s</svg>',
            $size, $aria, $bg, $d, $mark,
        );
    }

    /**
     * Data path modul gelap (satu baris = satu segmen horizontal), digeser sebesar quiet zone.
     *
     * @param  array{x:int,y:int,size:int}|null  $hole  kotak modul yang dikosongkan untuk logo
     */
    public static function path(Matrix $m, int $quiet = 0, ?array $hole = null): string
    {
        $dark = fn (int $x, int $y) => $m->get($x, $y) && ! ($hole
            && $x >= $hole['x'] && $x < $hole['x'] + $hole['size'] && $y >= $hole['y'] && $y < $hole['y'] + $hole['size']);
        $d = '';
        for ($y = 0; $y < $m->size; $y++) {
            $x = 0;
            while ($x < $m->size) {
                if (! $dark($x, $y)) {
                    $x++;

                    continue;
                }
                $start = $x;
                while ($x < $m->size && $dark($x, $y)) {
                    $x++;
                }
                $d .= sprintf('M%d %dh%dv1h-%dz', $start + $quiet, $y + $quiet, $x - $start, $x - $start);
            }
        }

        return $d;
    }

    public static function forUrl(string $url, ?string $background = null): string
    {
        $withLogo = QrEncoder::fits(strlen($url), 'H');
        $m = QrEncoder::encode($url, level: $withLogo ? 'H' : 'M');

        return self::render($m, background: $background, logo: $withLogo ? self::logoBox($m->size) : null);
    }

    /** Kotak di tengah dengan sisi berparitas sama dengan QR agar benar-benar di tengah. */
    private static function logoBox(int $size): array
    {
        $side = (int) round($size * self::LOGO_RATIO);
        if (($size - $side) % 2) {
            $side++;
        }
        $start = intdiv($size - $side, 2);

        return ['x' => $start, 'y' => $start, 'size' => $side];
    }

    /** Lencana logo di dalam kotak kosong (1 modul jarak tiap tepi); garis tanda dilubangi lewat mask agar ikut warna latar. */
    private static function mark(int $x, int $y, int $side): string
    {
        return sprintf(
            '<svg x="%1$d" y="%2$d" width="%3$d" height="%3$d" viewBox="0 0 24 24" shape-rendering="geometricPrecision"><mask id="tc-qr-mark"><rect width="24" height="24" rx="5" fill="white"/><path d="%4$s" fill="none" stroke="black" stroke-width="1.6" stroke-linecap="round"/></mask><rect width="24" height="24" rx="5" fill="currentColor" mask="url(#tc-qr-mark)"/></svg>',
            $x + 1, $y + 1, $side - 2, self::MARK,
        );
    }
}
