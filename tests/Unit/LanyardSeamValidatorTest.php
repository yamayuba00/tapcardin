<?php

namespace Tests\Unit;

use App\Support\Design\CardGeometry;
use App\Support\Design\DesignValidator;
use App\Support\Design\LanyardGeometry;
use PHPUnit\Framework\TestCase;

/** Tepi jahitan tali: bentuk dekoratif yang melewati margin melintang diberi peringatan; pita penuh tidak. */
class LanyardSeamValidatorTest extends TestCase
{
    private function issues(array $layers): array
    {
        return (new DesignValidator(new LanyardGeometry(20.0, 150.0)))->validate(['layers' => $layers], ['layers' => []]);
    }

    private function seamWarnings(array $issues): array
    {
        return array_values(array_filter($issues, fn ($i) => str_contains($i['message'], 'tepi tali')));
    }

    public function test_decorative_shape_crossing_the_seam_margin_is_flagged(): void
    {
        $w = $this->seamWarnings($this->issues([['id' => 'm1', 'type' => 'polygon', 'x' => 10, 'y' => 0.5, 'w' => 20, 'h' => 10, 'sides' => 3]]));
        $this->assertCount(1, $w);
        $this->assertSame('m1', $w[0]['layer']);
        $this->assertSame('warning', $w[0]['level']);
    }

    public function test_shape_inside_the_margins_is_not_flagged(): void
    {
        $this->assertSame([], $this->seamWarnings($this->issues([['id' => 'm2', 'type' => 'polygon', 'x' => 10, 'y' => 5, 'w' => 20, 'h' => 10, 'sides' => 3]])));
    }

    public function test_full_width_band_spanning_the_strap_is_exempt(): void
    {
        $this->assertSame([], $this->seamWarnings($this->issues([['id' => 'b', 'type' => 'rect', 'x' => 0, 'y' => 0, 'w' => 150, 'h' => 20]])));
    }

    public function test_card_geometry_does_not_use_the_strap_seam_rule(): void
    {
        $issues = (new DesignValidator(new CardGeometry))->validate(
            ['layers' => [['id' => 'c', 'type' => 'polygon', 'x' => 1, 'y' => 0.2, 'w' => 10, 'h' => 10, 'sides' => 3]]], ['layers' => []]);
        $this->assertSame([], $this->seamWarnings($issues));
    }
}
