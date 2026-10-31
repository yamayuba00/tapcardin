<?php

namespace App\Support\Design;

/** Geometri media cetak (kartu CR80 atau pita lanyard) — dipakai normalizer, validator, dan editor. */
interface Geometry
{
    public function kind(): string; // card | lanyard

    public function width(): float;

    public function height(): float;

    public function bleed(): float;

    public function safe(): float;

    public function portrait(): bool;

    /** @return array{x:float,y:float,w:float,h:float}|null */
    public function slotZone(): ?array;

    public function size(): array;

    public function zones(): array;
}
