<?php

namespace App\Support\Qr;

/** Empat aturan penalti ISO/IEC 18004 §7.8.3; mask dengan skor terendah dipilih. */
final class MaskEvaluator
{
    public static function penalty(Matrix $m): int
    {
        $n = $m->size;
        $score = 0;
        $rows = $m->modules;
        $cols = [];
        for ($x = 0; $x < $n; $x++) {
            $cols[$x] = array_column($rows, $x);
        }

        foreach ([$rows, $cols] as $lines) {
            foreach ($lines as $line) {
                $score += self::runs($line) + self::finderLike($line);
            }
        }

        // N2: blok 2×2 sewarna
        for ($y = 0; $y < $n - 1; $y++) {
            for ($x = 0; $x < $n - 1; $x++) {
                $c = $rows[$y][$x];
                if ($c === $rows[$y][$x + 1] && $c === $rows[$y + 1][$x] && $c === $rows[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }

        // N4: proporsi modul gelap
        $dark = 0;
        foreach ($rows as $row) {
            $dark += count(array_filter($row));
        }
        $percent = $dark * 100 / ($n * $n);
        $score += 10 * intdiv((int) abs($percent - 50), 5);

        return $score;
    }

    /** N1: deret ≥ 5 modul sewarna → 3 + (panjang − 5). @param bool[] $line */
    private static function runs(array $line): int
    {
        $score = 0;
        $run = 1;
        for ($i = 1, $len = count($line); $i <= $len; $i++) {
            if ($i < $len && $line[$i] === $line[$i - 1]) {
                $run++;

                continue;
            }
            if ($run >= 5) {
                $score += 3 + ($run - 5);
            }
            $run = 1;
        }

        return $score;
    }

    /** N3: pola 1:1:3:1:1 dengan 4 modul terang di salah satu sisi → 40. @param bool[] $line */
    private static function finderLike(array $line): int
    {
        $s = implode('', array_map(fn ($b) => $b ? '1' : '0', $line));

        return 40 * (substr_count($s, '10111010000') + substr_count($s, '00001011101'));
    }
}
