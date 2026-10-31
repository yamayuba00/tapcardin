<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ReservedSlugSeeder extends Seeder
{
    public function run(): void
    {
        $rows = array_map(fn ($s) => ['slug' => $s, 'reason' => 'sistem'], config('tapcard.reserved_slugs'));
        DB::table('reserved_slugs')->upsert($rows, ['slug'], ['reason']);
    }
}
