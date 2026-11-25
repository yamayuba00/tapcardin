<?php

namespace Tests\Feature;

use App\Models\CartItem;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use PDOException;
use Tests\TestCase;

/** Pemetaan galat ramah: tanpa nama model atau SQL di respons API produksi. */
class ErrorMappingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/api/_test/model-missing', fn () => throw (new ModelNotFoundException)->setModel(CartItem::class, [1]));
        Route::get('/api/_test/query-failed', fn () => throw new QueryException(
            'pgsql', 'select * from "secret_table" where id = ?', [1], new PDOException('SQLSTATE[42P01] relation secret_table'),
        ));
    }

    public function test_missing_model_returns_friendly_404(): void
    {
        $res = $this->getJson('/api/_test/model-missing')->assertNotFound()
            ->assertJsonPath('error.code', 'not_found')
            ->assertJsonPath('error.message', 'Data tidak ditemukan.');

        $this->assertStringNotContainsString('App\Models', $res->getContent());
        $this->assertStringNotContainsString('CartItem', $res->getContent());
    }

    public function test_query_exception_hides_sql_in_production(): void
    {
        config(['app.debug' => false]);

        $res = $this->getJson('/api/_test/query-failed')->assertStatus(500)
            ->assertJsonPath('error.code', 'server_error')
            ->assertJsonPath('error.message', 'Terjadi kesalahan di server. Coba lagi sebentar.');

        $this->assertStringNotContainsString('secret_table', $res->getContent());
        $this->assertStringNotContainsString('SQLSTATE', $res->getContent());
    }
}
