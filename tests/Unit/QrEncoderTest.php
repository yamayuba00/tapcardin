<?php

namespace Tests\Unit;

use App\Support\Qr\Matrix;
use App\Support\Qr\QrEncoder;
use App\Support\Qr\ReedSolomon;
use App\Support\Qr\SvgRenderer;
use PHPUnit\Framework\TestCase;

/**
 * TRD §8.2 — polinom generator RS, info format 8 mask, struktur matriks.
 * Uji decode ujung-ke-ujung (zbarimg/zxing) dijalankan terpisah di CI.
 */
class QrEncoderTest extends TestCase
{
    public function test_reed_solomon_generator_matches_iso_table(): void
    {
        // ISO/IEC 18004 Annex A: g(x) derajat 7 dalam notasi eksponen α: 87, 229, 146, 149, 238, 102, 21
        $exp = [1];
        for ($i = 1; $i < 255; $i++) {
            $exp[$i] = ReedSolomon::mul($exp[$i - 1], 2);
        }
        $log = array_flip($exp);
        $this->assertSame([87, 229, 146, 149, 238, 102, 21], array_map(fn ($c) => $log[$c], ReedSolomon::generator(7)));
    }

    public function test_reed_solomon_remainder_known_vector(): void
    {
        // Contoh klasik "HELLO WORLD" 1-M: 16 codeword data → 10 EC
        $data = [32, 91, 11, 120, 209, 114, 220, 77, 67, 64, 236, 17, 236, 17, 236, 17];
        $this->assertSame([196, 35, 39, 119, 235, 215, 231, 226, 93, 23], ReedSolomon::remainder($data, 10));
    }

    public function test_format_bits_for_level_m_all_masks(): void
    {
        $expected = [0x5412, 0x5125, 0x5E7C, 0x5B4B, 0x45F9, 0x40CE, 0x4F97, 0x4AA0];
        foreach ($expected as $mask => $bits) {
            $this->assertSame($bits, Matrix::formatBits(0b00, $mask), "mask $mask");
        }
    }

    public function test_version_selection_for_card_urls(): void
    {
        $this->assertSame(3, QrEncoder::pickVersion(42)); // https://tap.domain.com/c/{token}?src=qr
        $this->assertSame(4, QrEncoder::pickVersion(43));
        $this->assertSame(4, QrEncoder::pickVersion(62));
        $this->assertSame(5, QrEncoder::pickVersion(84));
        $this->expectException(\InvalidArgumentException::class);
        QrEncoder::pickVersion(200);
    }

    public function test_matrix_has_finder_timing_and_dark_module(): void
    {
        $m = QrEncoder::encode('https://tap.domain.com/c/aB3dE5fG7h?src=qr');
        $n = $m->size;
        $this->assertSame(17 + 4 * $m->version, $n);
        foreach ([[0, 0], [$n - 7, 0], [0, $n - 7]] as [$x0, $y0]) {
            for ($i = 0; $i < 7; $i++) {
                $this->assertTrue($m->get($x0 + $i, $y0), 'tepi atas finder');
                $this->assertTrue($m->get($x0 + $i, $y0 + 6), 'tepi bawah finder');
            }
            $this->assertFalse($m->get($x0 + 1, $y0 + 1), 'cincin terang finder');
            $this->assertTrue($m->get($x0 + 3, $y0 + 3), 'pusat finder');
        }
        for ($i = 8; $i < $n - 8; $i++) {
            $this->assertSame($i % 2 === 0, $m->get($i, 6), "timing horizontal $i");
            $this->assertSame($i % 2 === 0, $m->get(6, $i), "timing vertikal $i");
        }
        $this->assertTrue($m->get(8, $n - 8), 'dark module');
    }

    public function test_svg_uses_current_color_and_quiet_zone(): void
    {
        $svg = SvgRenderer::forUrl('https://tap.domain.com/c/aB3dE5fG7h?src=qr');
        $this->assertStringContainsString('fill="currentColor"', $svg);
        $this->assertStringContainsString('viewBox="0 0 37 37"', $svg); // V3 = 29 modul + 2×4 quiet zone
        $this->assertDoesNotMatchRegularExpression('/#[0-9a-f]{6}/i', $svg);
        $this->assertSame(1, substr_count($svg, '<path'));
    }
}
