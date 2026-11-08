<?php

namespace Tests\Feature;

use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Penjaga zona waktu (App\Database\PostgresConnection): Carbon berzona WIB di query & kolom model tidak bergeser 7 jam.
 * Tanpa penjaga, kedua tes ini gagal pada jam berapa pun.
 */
class TimezoneGuardTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_wib_boundaries_in_queries_match_the_same_instant(): void
    {
        $this->travelTo(Carbon::parse('2026-11-08 01:30:00', 'Asia/Jakarta')); // 7 Nov 18.30 UTC
        $rina = $this->makeUser();
        DB::table('notifications')->insert([
            ['user_id' => $rina->id, 'type' => 'tap', 'data' => '{}', 'created_at' => Carbon::parse('2026-11-08 00:30', 'Asia/Jakarta')->utc()], // hari ini WIB
            ['user_id' => $rina->id, 'type' => 'tap', 'data' => '{}', 'created_at' => Carbon::parse('2026-11-07 23:30', 'Asia/Jakarta')->utc()], // kemarin WIB
        ]);

        $today = DB::table('notifications')->where('created_at', '>=', now('Asia/Jakarta')->startOfDay())->count();
        $this->assertSame(1, $today, 'awal hari WIB tidak boleh terbaca sebagai 00.00 UTC (= 07.00 WIB)');
        $this->assertSame(0, DB::table('notifications')->where('created_at', '>', now('Asia/Jakarta')->endOfDay())->count());
    }

    public function test_wib_carbon_saved_to_a_model_keeps_the_instant(): void
    {
        $rina = $this->makeUser();
        $due = Carbon::parse('2026-11-10 09:00', 'Asia/Jakarta');
        $inv = Invoice::acrossTenants()->create([
            'tenant_id' => $rina->personal_tenant_id, 'number' => 'INV/TZ/1', 'type' => 'subscription', 'status' => 'issued',
            'lines' => [], 'subtotal' => 1, 'total' => 1, 'due_at' => $due,
        ]);
        $this->assertSame($due->utc()->toIso8601String(), $inv->fresh()->due_at->utc()->toIso8601String());
        $this->assertSame('2026-11-10 02:00:00+00', DB::selectOne("select to_char(due_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') || '+00' as v from invoices where id = ?", [$inv->id])->v);
    }

    public function test_clock_override_is_applied(): void
    {
        if (! getenv('TEST_CLOCK')) {
            $this->markTestSkipped('Hanya saat TEST_CLOCK diisi (CI: jam rawan WIB).');
        }
        $this->assertSame(Carbon::parse(getenv('TEST_CLOCK'))->timestamp, now()->timestamp);
    }
}
