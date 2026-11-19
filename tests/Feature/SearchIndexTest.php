<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Kotak cari `ilike '%q%'` di tabel yang tumbuh punya index trigram (pg_trgm) yang valid. */
class SearchIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_trigram_indexes_exist_for_searched_columns(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_extension where extname = 'pg_trgm'"));
        $rows = DB::select("select c.relname as name, i.indisvalid as valid from pg_index i join pg_class c on c.oid = i.indexrelid where c.relname like 'trgm_%'");
        $names = array_column($rows, 'name');
        foreach (['trgm_users_email', 'trgm_users_name', 'trgm_tenants_slug', 'trgm_connections_name', 'trgm_connections_phone', 'trgm_orders_number', 'trgm_invoices_number'] as $index) {
            $this->assertContains($index, $names);
        }
        $this->assertSame(count($rows), count(array_filter($rows, fn ($r) => $r->valid)));
    }
}
