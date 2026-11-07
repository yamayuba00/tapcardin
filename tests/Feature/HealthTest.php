<?php

namespace Tests\Feature;

use App\Console\Commands\Health;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** `tapcard:health` membaca antrean lewat API Queue: sama benarnya untuk driver database maupun redis. */
class HealthTest extends TestCase
{
    use RefreshDatabase;

    private function check(array $h, string $label): ?array
    {
        return collect($h['checks'])->firstWhere('label', $label);
    }

    public function test_counts_pending_jobs_across_all_worker_queues(): void
    {
        config(['queue.default' => 'database', 'cache.default' => 'database', 'session.driver' => 'database']);
        Queue::connection('database')->pushRaw(json_encode(['displayName' => 'a', 'job' => 'x', 'data' => []]), 'taps');
        Queue::connection('database')->pushRaw(json_encode(['displayName' => 'b', 'job' => 'x', 'data' => []]), 'notify');
        Queue::connection('database')->pushRaw(json_encode(['displayName' => 'c', 'job' => 'x', 'data' => []]), 'render');

        $h = Health::collect();
        $this->assertSame('3', $this->check($h, 'Job menunggu')['value']);
        $this->assertNotNull($this->check($h, 'Ukuran tabel jobs'));
        $this->assertNotNull($this->check($h, 'Pengguna online (5 menit)'));
        $this->assertSame('database', $h['drivers']['queue']);
    }

    public function test_redis_mode_hides_database_tables_and_reports_unreachable_redis(): void
    {
        // tanpa server Redis: antrean & memori dilaporkan gagal, tapi pemeriksaan lain tetap jalan
        config(['queue.default' => 'redis', 'cache.default' => 'redis', 'session.driver' => 'database',
            'database.redis.default.host' => '127.0.0.1', 'database.redis.default.port' => 1, 'database.redis.default.max_retries' => 0]);

        $h = Health::collect();
        $labels = collect($h['checks'])->pluck('label');
        $this->assertFalse($labels->contains('Ukuran tabel jobs'));
        $this->assertFalse($labels->contains('Ukuran tabel cache'));
        $this->assertTrue($labels->contains('Ukuran tabel sessions'), 'sesi tetap di database');
        $this->assertFalse($this->check($h, 'Antrean (redis)')['ok']);
        $this->assertFalse($h['redis_recommended'], 'sudah memakai Redis');
        $this->assertNotNull($this->check($h, 'Job gagal 24 jam'));
    }
}
