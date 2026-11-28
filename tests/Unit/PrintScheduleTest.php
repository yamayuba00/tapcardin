<?php

namespace Tests\Unit;

use App\Support\Production\PrintSchedule;
use App\Support\Production\WorkingCalendar;
use PHPUnit\Framework\TestCase;

/** Estimasi cetak (murni, tanpa DB): kalender hari kerja, lead time min/maks, dan pembagian kapasitas harian. */
class PrintScheduleTest extends TestCase
{
    // 2026-12-04 = Jumat, 05 = Sabtu, 07 = Senin
    private function cal(array $holidays = []): WorkingCalendar
    {
        return new WorkingCalendar($holidays);
    }

    private function card(int|string $key, int $qty, int $min = 3, int $max = 7): array
    {
        return ['key' => $key, 'kind' => 'card', 'quantity' => $qty, 'min_days' => $min, 'max_days' => $max];
    }

    public function test_weekends_and_holidays_are_not_working_days(): void
    {
        $cal = $this->cal(['2026-12-08']);
        $this->assertFalse($cal->isWorkingDay(WorkingCalendar::date('2026-12-05')), 'Sabtu');
        $this->assertFalse($cal->isWorkingDay(WorkingCalendar::date('2026-12-06')), 'Minggu');
        $this->assertFalse($cal->isWorkingDay(WorkingCalendar::date('2026-12-08')), 'libur');
        $this->assertTrue($cal->isWorkingDay(WorkingCalendar::date('2026-12-07')), 'Senin');
    }

    public function test_next_working_day_skips_weekend_and_holidays(): void
    {
        $this->assertSame('2026-12-07', $this->cal()->nextWorkingDay(WorkingCalendar::date('2026-12-04'))->format('Y-m-d'), 'Jumat → Senin');
        $this->assertSame('2026-12-07', $this->cal()->nextWorkingDay(WorkingCalendar::date('2026-12-05'))->format('Y-m-d'), 'Sabtu → Senin');
        $this->assertSame('2026-12-09', $this->cal(['2026-12-08'])->nextWorkingDay(WorkingCalendar::date('2026-12-07'))->format('Y-m-d'), 'Senin libur → Rabu');
    }

    public function test_add_working_days_and_counting_skip_non_working_days(): void
    {
        $cal = $this->cal(['2026-12-08']);
        $this->assertSame('2026-12-11', $cal->addWorkingDays(WorkingCalendar::date('2026-12-07'), 3)->format('Y-m-d'));
        $this->assertSame(2, $cal->workingDaysBetween(WorkingCalendar::date('2026-12-07'), WorkingCalendar::date('2026-12-10')));
    }

    public function test_lead_time_min_and_max_from_start_day(): void
    {
        $p = PrintSchedule::plan([$this->card('a', 1, 3, 7)], '2026-12-07', [], 200, $this->cal());
        $this->assertSame('2026-12-07', $p['items']['a']['starts_on']);
        $this->assertSame('2026-12-10', $p['items']['a']['eta_min'], 'min = mulai + 3 hari kerja');
        $this->assertSame('2026-12-16', $p['items']['a']['eta_max'], 'maks = mulai + 7 hari kerja');
        $this->assertSame(0, $p['delay_days']);
        $this->assertNull($p['note']);
    }

    public function test_capacity_spills_to_next_working_day(): void
    {
        $p = PrintSchedule::plan([$this->card('a', 150)], '2026-12-07', [], 100, $this->cal());
        $item = $p['items']['a'];
        $this->assertSame(['2026-12-07' => 100, '2026-12-08' => 50], $item['allocations']);
        $this->assertSame('2026-12-08', $item['last_day'], 'selesai dihitung dari hari cetak terakhir');
        $this->assertSame('2026-12-11', $item['eta_min']);
        $this->assertSame('2026-12-17', $item['eta_max']);
        $this->assertSame(1, $p['delay_days']);
        $this->assertStringContainsString('mundur 1 hari kerja', (string) $p['note']);
    }

    public function test_bookings_of_other_orders_reduce_capacity(): void
    {
        $p = PrintSchedule::plan([$this->card('a', 30)], '2026-12-07', ['2026-12-07' => 90], 100, $this->cal());
        $this->assertSame(['2026-12-07' => 10, '2026-12-08' => 20], $p['items']['a']['allocations']);
    }

    public function test_lines_of_one_plan_share_capacity_in_order(): void
    {
        $p = PrintSchedule::plan([$this->card('a', 80), $this->card('b', 50)], '2026-12-07', [], 100, $this->cal());
        $this->assertSame(['2026-12-07' => 80], $p['items']['a']['allocations']);
        $this->assertSame(['2026-12-07' => 20, '2026-12-08' => 30], $p['items']['b']['allocations']);
        $this->assertSame('2026-12-08', $p['items']['b']['last_day']);
    }

    public function test_lanyard_does_not_use_card_capacity(): void
    {
        $lanyard = ['key' => 'l', 'kind' => 'lanyard', 'quantity' => 500, 'min_days' => 3, 'max_days' => 7];
        $p = PrintSchedule::plan([$lanyard], '2026-12-07', [], 1, $this->cal());
        $this->assertSame([], $p['items']['l']['allocations']);
        $this->assertSame('2026-12-10', $p['items']['l']['eta_min']);
        $this->assertSame(0, $p['delay_days']);
    }

    public function test_summary_takes_earliest_min_and_latest_max(): void
    {
        $p = PrintSchedule::plan([$this->card('fast', 1, 1, 2), $this->card('slow', 1, 5, 9)], '2026-12-07', [], 200, $this->cal());
        $this->assertSame('2026-12-08', $p['eta_min']);
        $this->assertSame('2026-12-18', $p['eta_max']);
    }
}
