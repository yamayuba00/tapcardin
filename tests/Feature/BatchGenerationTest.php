<?php

namespace Tests\Feature;

use App\Jobs\FinalizeBatchBundle;
use App\Jobs\GenerateBatchChunk;
use App\Models\Batch;
use App\Models\Card;
use App\Models\PlatformStaff;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Token batch dibuat di latar per 50 kartu: berurutan, bisa dilanjutkan setelah gagal, tidak dobel, bundle lengkap. */
class BatchGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $u = $this->makeUser('produksi@tapcard.test', 'Produksi');
        PlatformStaff::create(['user_id' => $u->id, 'role_id' => Role::system('production')->id, 'status' => 'active', 'last_reverified_at' => now()]);
        $this->actingAs($u);
    }

    private function api(string $method, string $path, array $data = [])
    {
        return $this->json($method, $this->host('panel', "/api$path"), $data);
    }

    private function batch(int $qty): Batch
    {
        $id = $this->api('POST', '/batches', ['chip_type' => 'ntag215', 'quantity' => $qty])->assertCreated()->json('data.id');

        return Batch::where('public_id', $id)->firstOrFail();
    }

    /** @return list<array> baris data bundle (tanpa header) */
    private function rows(Batch $b): array
    {
        $lines = array_values(array_filter(explode("\n", Storage::disk('local')->get($b->fresh()->print_bundle_path))));
        $this->assertSame('urutan,token,kode_pendek,kode_aktivasi,url_nfc,url_qr', $lines[0]);

        return array_map('str_getcsv', array_slice($lines, 1));
    }

    public function test_request_returns_immediately_and_queues_ordered_chunks_of_50(): void
    {
        Queue::fake();
        $b = $this->batch(120);
        $this->api('POST', "/batches/{$b->public_id}/generate")->assertOk()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.generation.running', true)->assertJsonPath('data.counts.tokens', 0);

        Queue::assertPushedOn('render', GenerateBatchChunk::class, fn ($j) => [$j->from, $j->to] === [1, 50]);
        Queue::assertPushedWithChain(GenerateBatchChunk::class, [
            new GenerateBatchChunk($b->id, 51, 100), new GenerateBatchChunk($b->id, 101, 120), new FinalizeBatchBundle($b->id),
        ]);
        $this->api('POST', "/batches/{$b->public_id}/generate")->assertJsonPath('error.code', 'batch_generating');
    }

    public function test_full_run_builds_complete_ordered_bundle_with_valid_activation_codes(): void
    {
        $b = $this->batch(120);
        $this->api('POST', "/batches/{$b->public_id}/generate")->assertOk()
            ->assertJsonPath('data.status', 'tokens_generated')->assertJsonPath('data.counts.tokens', 120)
            ->assertJsonPath('data.has_bundle', true)->assertJsonPath('data.generation', null);

        $rows = $this->rows($b);
        $this->assertSame(range(1, 120), array_map('intval', array_column($rows, 0)));
        $cards = Card::acrossTenants()->where('batch_id', $b->id)->orderBy('sequence')->get()->keyBy('sequence');
        foreach ([1, 50, 51, 120] as $seq) {
            $this->assertSame($cards[$seq]->card_token, $rows[$seq - 1][1]);
            $this->assertTrue(Hash::check($rows[$seq - 1][3], $cards[$seq]->activation_code_hash), "kode aktivasi kartu $seq cocok");
        }
        $this->assertSame([], Storage::disk('local')->directories('batches'), 'file bagian dibersihkan');
        $this->assertDatabaseHas('audit_logs', ['action' => 'batch.tokens_generated']);
    }

    public function test_failed_chunk_can_be_resumed_without_duplicates_or_lost_codes(): void
    {
        $b = $this->batch(120);
        // potongan pertama selesai, lalu potongan kedua gagal (mis. DB putus)
        $b->update(['tokens_started_at' => now()]);
        (new GenerateBatchChunk($b->id, 1, 50))->handle();
        (new GenerateBatchChunk($b->id, 1, 50))->handle(); // percobaan ulang: dilewati, tidak dobel
        (new GenerateBatchChunk($b->id, 51, 100))->failed(new \RuntimeException('server closed the connection'));
        $this->assertSame(50, $b->cards()->count());

        $this->api('GET', "/batches/{$b->public_id}")
            ->assertJsonPath('data.generation.running', false)->assertJsonPath('data.generation.error', 'Kartu 51–100: server closed the connection');
        $this->api('POST', "/batches/{$b->public_id}/generate")->assertOk()->assertJsonPath('data.status', 'tokens_generated');

        $rows = $this->rows($b);
        $this->assertSame(range(1, 120), array_map('intval', array_column($rows, 0)), 'kode aktivasi 1–50 dari percobaan pertama ikut di bundle');
        $this->assertSame(120, DB::table('cards')->where('batch_id', $b->id)->distinct()->count('sequence'));
        $first = Card::acrossTenants()->where('batch_id', $b->id)->where('sequence', 1)->first();
        $this->assertTrue(Hash::check($rows[0][3], $first->activation_code_hash));
    }

    public function test_cancelled_batch_stops_remaining_chunks_and_stall_is_reported(): void
    {
        $b = $this->batch(60);
        $b->update(['tokens_started_at' => now()->subMinutes(20)]);
        Batch::whereKey($b->id)->update(['updated_at' => now()->subMinutes(20)]);
        $this->api('GET', "/batches/{$b->public_id}")->assertJsonPath('data.generation.stalled', true)->assertJsonPath('data.generation.running', false);

        $this->api('POST', "/batches/{$b->public_id}/status", ['status' => 'cancelled'])->assertOk();
        (new GenerateBatchChunk($b->id, 1, 50))->handle();
        $this->assertSame(0, $b->cards()->count(), 'batch dibatalkan: tidak ada kartu dibuat');
    }
}
