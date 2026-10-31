<?php

namespace App\Support\Qr;

/** Matriks modul QR. Koordinat ($x kolom, $y baris). */
final class Matrix
{
    public readonly int $size;

    /** @var array<int, array<int, bool>> [$y][$x] gelap? */
    public array $modules;

    /** @var array<int, array<int, bool>> [$y][$x] modul fungsi (tidak kena mask/data) */
    private array $isFunction;

    public function __construct(public readonly int $version)
    {
        $this->size = 17 + 4 * $version;
        $row = array_fill(0, $this->size, false);
        $this->modules = array_fill(0, $this->size, $row);
        $this->isFunction = array_fill(0, $this->size, $row);
    }

    public function get(int $x, int $y): bool
    {
        return $this->modules[$y][$x];
    }

    public function drawFunctionPatterns(int $alignCenter): void
    {
        // pola timing
        for ($i = 0; $i < $this->size; $i++) {
            $this->setFunction(6, $i, $i % 2 === 0);
            $this->setFunction($i, 6, $i % 2 === 0);
        }
        // tiga pola finder + separator
        $this->drawFinder(3, 3);
        $this->drawFinder($this->size - 4, 3);
        $this->drawFinder(3, $this->size - 4);
        // satu pola alignment (V2–V6)
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $this->setFunction($alignCenter + $dx, $alignCenter + $dy, max(abs($dx), abs($dy)) !== 1);
            }
        }
        // cadangkan area info format (diisi nanti) + dark module
        $this->drawFormatBits(0, 0);
    }

    private function drawFinder(int $cx, int $cy): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $x = $cx + $dx;
                $y = $cy + $dy;
                if ($x < 0 || $x >= $this->size || $y < 0 || $y >= $this->size) {
                    continue;
                }
                $d = max(abs($dx), abs($dy));
                $this->setFunction($x, $y, $d !== 2 && $d !== 4);
            }
        }
    }

    /** Info format: 5 bit (level EC + mask) + BCH(15,5) generator 0x537, XOR 0x5412. */
    public function drawFormatBits(int $ecBits, int $mask): void
    {
        $bits = self::formatBits($ecBits, $mask);
        $bit = fn (int $i) => (($bits >> $i) & 1) === 1;

        // salinan pertama (sekitar finder kiri atas)
        for ($i = 0; $i <= 5; $i++) {
            $this->setFunction(8, $i, $bit($i));
        }
        $this->setFunction(8, 7, $bit(6));
        $this->setFunction(8, 8, $bit(7));
        $this->setFunction(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $this->setFunction(14 - $i, 8, $bit($i));
        }
        // salinan kedua (finder kanan atas & kiri bawah)
        for ($i = 0; $i < 8; $i++) {
            $this->setFunction($this->size - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->setFunction(8, $this->size - 15 + $i, $bit($i));
        }
        $this->setFunction(8, $this->size - 8, true); // dark module
    }

    public static function formatBits(int $ecBits, int $mask): int
    {
        $data = ($ecBits << 3) | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }

        return (($data << 10) | ($rem & 0x3FF)) ^ 0x5412;
    }

    /** Penempatan zig-zag dua kolom dari kanan bawah (ISO/IEC 18004 §7.7.3). @param int[] $codewords */
    public function drawCodewords(array $codewords): void
    {
        $total = count($codewords) * 8;
        $i = 0;
        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5; // lewati kolom timing vertikal
            }
            for ($vert = 0; $vert < $this->size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $this->size - 1 - $vert : $vert;
                    if (! $this->isFunction[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = (($codewords[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                    // sisa bit (remainder) dibiarkan terang
                }
            }
        }
    }

    public function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->isFunction[$y][$x]) {
                    continue;
                }
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
                    6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
                    7 => (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0,
                };
                if ($invert) {
                    $this->modules[$y][$x] = ! $this->modules[$y][$x];
                }
            }
        }
    }

    private function setFunction(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->isFunction[$y][$x] = true;
    }
}
