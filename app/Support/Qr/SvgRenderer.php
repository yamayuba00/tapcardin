<?php

namespace App\Support\Qr;

/**
 * Satu <path> per gambar, quiet zone 4 modul. Warna memakai `currentColor` agar diatur
 * token CSS (DBD §3.4 — tidak ada hex di luar tokens.css).
 */
final class SvgRenderer
{
    public static function render(Matrix $m, int $quiet = 4, ?string $label = 'Kode QR', ?string $background = null): string
    {
        $size = $m->size + 2 * $quiet;
        $d = self::path($m, $quiet);
        $bg = $background !== null
            ? sprintf('<rect width="%d" height="%d" fill="%s"/>', $size, $size, htmlspecialchars($background))
            : '';
        $aria = $label !== null ? sprintf(' role="img" aria-label="%s"', htmlspecialchars($label)) : ' aria-hidden="true"';

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d" shape-rendering="crispEdges"%2$s>%3$s<path fill="currentColor" d="%4$s"/></svg>',
            $size, $aria, $bg, $d,
        );
    }

    /** Data path modul gelap (satu baris = satu segmen horizontal), digeser sebesar quiet zone. */
    public static function path(Matrix $m, int $quiet = 0): string
    {
        $d = '';
        for ($y = 0; $y < $m->size; $y++) {
            $x = 0;
            while ($x < $m->size) {
                if (! $m->get($x, $y)) {
                    $x++;

                    continue;
                }
                $start = $x;
                while ($x < $m->size && $m->get($x, $y)) {
                    $x++;
                }
                $d .= sprintf('M%d %dh%dv1h-%dz', $start + $quiet, $y + $quiet, $x - $start, $x - $start);
            }
        }

        return $d;
    }

    public static function forUrl(string $url, ?string $background = null): string
    {
        return self::render(QrEncoder::encode($url), background: $background);
    }
}
