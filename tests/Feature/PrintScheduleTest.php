<?php

namespace Tests\Feature;

use App\Models\CardProduct;
use App\Models\Design;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Estimasi cetak end-to-end: quote di checkout, jadwal tersimpan saat lunas, dan detail pesanan. */
class PrintScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function app_(string $method, string $path, array $data = [])
    {
        return $this->json($method, $this->host('app', "/api$path"), $data);
    }

    /** Desain kartu yang sudah lolos cek cetak. */
    private function cardDesign(User $u): string
    {
        $this->actingAs($u);
        $id = $this->app_('POST', '/designs', ['template' => 'onyx'])->assertCreated()->json('data.id');
        Design::withoutGlobalScope(TenantScope::class)->where('public_id', $id)->update(['validated_at' => now()]);

        return $id;
    }

    private function items(string $design, int $qty): array
    {
        return [['kind' => 'card', 'design' => $design, 'product' => 'pvc_ntag215', 'quantity' => $qty]];
    }

    private function quote(string $design, int $qty)
    {
        return $this->app_('POST', '/orders/quote', ['items' => $this->items($design, $qty), 'method' => 'regular']);
    }

    private function order(string $design, int $qty)
    {
        return $this->app_('POST', '/orders', [
            'items' => $this->items($design, $qty),
            'shipping' => ['method' => 'regular', 'recipient_name' => 'Rina', 'recipient_phone' => '081234567890', 'address_line' => 'Jl. Sudirman No. 1, RT 02/RW 03', 'district' => 'Tanah Abang', 'city' => 'Jakarta Pusat', 'province' => 'DKI Jakarta', 'postal_code' => '10210'],
        ])->assertCreated()->json('data');
    }

    /** Bayar pesanan lewat simulator pembayaran (jalur yang sama dengan webhook). */
    private function pay(string $orderPublicId): void
    {
        $orderId = Order::withoutGlobalScopes()->where('public_id', $orderPublicId)->value('id');
        $inv = Invoice::withoutGlobalScope(TenantScope::class)->where('order_id', $orderId)->firstOrFail();
        $this->app_('POST', "/billing/invoices/{$inv->public_id}/simulate", ['result' => 'paid', 'method' => 'QRIS'])->assertJsonPath('data.status', 'paid');
    }

    private function itemRow(string $orderPublicId): object
    {
        return DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')
            ->where('o.public_id', $orderPublicId)->select('i.*')->firstOrFail();
    }

    public function test_quote_contains_print_dates_and_no_note_for_small_order(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-04 10:00', 'Asia/Jakarta')); // Jumat
        $u = $this->makeUser();
        $d = $this->cardDesign($u);

        $this->quote($d, 2)->assertOk()
            ->assertJsonPath('data.print_eta_min', '2026-12-10')
            ->assertJsonPath('data.print_eta_max', '2026-12-16')
            ->assertJsonPath('data.print_note', null);
    }

    public function test_order_paid_on_friday_starts_printing_on_monday_and_stores_schedule(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-04 10:00', 'Asia/Jakarta'));
        $u = $this->makeUser();
        $d = $this->cardDesign($u);
        $o = $this->order($d, 2);

        $this->pay($o['order']['id']);

        $row = $this->itemRow($o['order']['id']);
        $this->assertSame('2026-12-07', $row->print_starts_on, 'mulai hari kerja setelah Jumat = Senin');
        $this->assertSame('2026-12-10', $row->print_eta_min, 'Senin + 3 hari kerja');
        $this->assertSame('2026-12-16', $row->print_eta_max, 'Senin + 7 hari kerja');
        $this->assertSame(2, DB::table('order_item_schedules')->where('order_item_id', $row->id)->sum('quantity'));

        $this->app_('GET', "/orders/{$o['order']['id']}")->assertOk()
            ->assertJsonPath('data.print.eta_min', '2026-12-10')
            ->assertJsonPath('data.print.eta_max', '2026-12-16')
            ->assertJsonPath('data.items.0.print.starts_on', '2026-12-07')
            ->assertJsonPath('data.items.0.print.estimated', false);
    }

    public function test_lead_time_comes_from_card_product_settings(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-04 10:00', 'Asia/Jakarta'));
        CardProduct::where('key', 'pvc_ntag215')->update(['print_min_days' => 2, 'print_max_days' => 5]);
        $u = $this->makeUser();

        $this->quote($this->cardDesign($u), 1)->assertOk()
            ->assertJsonPath('data.print_eta_min', '2026-12-09')
            ->assertJsonPath('data.print_eta_max', '2026-12-14');
    }

    public function test_weekend_and_holiday_are_skipped_in_the_schedule(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-05 10:00', 'Asia/Jakarta')); // Sabtu
        config(['production.holidays' => ['2026-12-07']]);
        $u = $this->makeUser();

        // mulai Selasa 8 Des (Senin libur): min = 8 + 3 hari kerja = 11 Des, maks = 8 + 7 = 17 Des
        $this->quote($this->cardDesign($u), 1)->assertOk()
            ->assertJsonPath('data.print_eta_min', '2026-12-11')
            ->assertJsonPath('data.print_eta_max', '2026-12-17');
    }

    public function test_capacity_spill_moves_finish_date_and_adds_note(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-04 10:00', 'Asia/Jakarta'));
        PlatformSetting::put('production.cards_per_day', 100);
        $u = $this->makeUser();
        $d = $this->cardDesign($u);

        // 150 kartu, 100 per hari → Senin 100 + Selasa 50; selesai dihitung dari Selasa
        $this->quote($d, 150)->assertOk()
            ->assertJsonPath('data.print_eta_min', '2026-12-11')
            ->assertJsonPath('data.print_eta_max', '2026-12-17')
            ->assertJsonPath('data.print_note', 'Kapasitas cetak harian sedang terisi, jadi estimasi cetak mundur 1 hari kerja.');
    }

    public function test_orders_already_paid_keep_their_schedule_when_capacity_changes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-04 10:00', 'Asia/Jakarta'));
        PlatformSetting::put('production.cards_per_day', 200);
        $u = $this->makeUser();
        $d = $this->cardDesign($u);

        $first = $this->order($d, 2);
        $this->pay($first['order']['id']);
        $before = $this->itemRow($first['order']['id']);

        // kapasitas diturunkan drastis lalu pesanan kedua lunas → pesanan pertama tidak ikut bergeser
        PlatformSetting::put('production.cards_per_day', 1);
        $second = $this->order($d, 50);
        $this->pay($second['order']['id']);

        $after = $this->itemRow($first['order']['id']);
        $this->assertSame($before->print_eta_min, $after->print_eta_min);
        $this->assertSame($before->print_eta_max, $after->print_eta_max);
        $this->assertSame($before->print_starts_on, $after->print_starts_on);
        $this->assertSame(0, (int) $after->print_delay_days);

        $this->assertSame('2026-12-10', $this->itemRow($first['order']['id'])->print_eta_min);
        $this->assertGreaterThan(0, (int) $this->itemRow($second['order']['id'])->print_delay_days, 'pesanan baru kena kapasitas kecil');
    }

    public function test_cards_booked_by_earlier_paid_order_reduce_capacity_of_next_order(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-04 10:00', 'Asia/Jakarta'));
        PlatformSetting::put('production.cards_per_day', 100);
        $u = $this->makeUser();
        $d = $this->cardDesign($u);

        $first = $this->order($d, 80);
        $this->pay($first['order']['id']);

        // Senin sisa 20; 30 kartu → 20 Senin + 10 Selasa → selesai dihitung dari Selasa
        $second = $this->order($d, 30);
        $this->pay($second['order']['id']);

        $row = $this->itemRow($second['order']['id']);
        $this->assertSame('2026-12-08', DB::table('order_item_schedules')->where('order_item_id', $row->id)->where('quantity', 10)->value('day'));
        $this->assertSame('2026-12-11', $row->print_eta_min);
    }
}
