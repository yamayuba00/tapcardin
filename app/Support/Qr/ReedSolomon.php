<?php

namespace App\Support\Qr;

/** Reed–Solomon di GF(256) dengan polinom primitif 0x11D (ISO/IEC 18004). */
final class ReedSolomon
{
    /** @var int[] */
    private static array $exp = [];

    /** @var int[] */
    private static array $log = [];

    private static function init(): void
    {
        if (self::$exp) {
            return;
        }
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$exp[$i] = $x;
            self::$log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            self::$exp[$i] = self::$exp[$i - 255];
        }
    }

    public static function mul(int $a, int $b): int
    {
        self::init();
        if ($a === 0 || $b === 0) {
            return 0;
        }

        return self::$exp[self::$log[$a] + self::$log[$b]];
    }

    /**
     * Koefisien polinom generator derajat $degree, dari pangkat tertinggi (tanpa koefisien 1 terdepan).
     *
     * @return int[]
     */
    public static function generator(int $degree): array
    {
        self::init();
        $poly = [1];
        for ($i = 0; $i < $degree; $i++) {
            // kalikan dengan (x - α^i); di GF(2^8) pengurangan = XOR
            $next = array_fill(0, count($poly) + 1, 0);
            foreach ($poly as $j => $coef) {
                $next[$j] ^= $coef;
                $next[$j + 1] ^= self::mul($coef, self::$exp[$i]);
            }
            $poly = $next;
        }

        return array_slice($poly, 1);
    }

    /**
     * @param  int[]  $data
     * @return int[] codeword koreksi sepanjang $ecLength
     */
    public static function remainder(array $data, int $ecLength): array
    {
        $gen = self::generator($ecLength);
        $rem = array_fill(0, $ecLength, 0);
        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($rem);
            $rem[] = 0;
            foreach ($gen as $i => $g) {
                $rem[$i] ^= self::mul($g, $factor);
            }
        }

        return $rem;
    }
}
