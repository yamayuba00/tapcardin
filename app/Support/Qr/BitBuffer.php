<?php

namespace App\Support\Qr;

final class BitBuffer
{
    /** @var int[] */
    private array $bits = [];

    public function append(int $value, int $length): void
    {
        for ($i = $length - 1; $i >= 0; $i--) {
            $this->bits[] = ($value >> $i) & 1;
        }
    }

    public function length(): int
    {
        return count($this->bits);
    }

    /** @return int[] */
    public function toBytes(): array
    {
        $bytes = [];
        foreach (array_chunk($this->bits, 8) as $chunk) {
            $b = 0;
            foreach (array_pad($chunk, 8, 0) as $bit) {
                $b = ($b << 1) | $bit;
            }
            $bytes[] = $b;
        }

        return $bytes;
    }
}
