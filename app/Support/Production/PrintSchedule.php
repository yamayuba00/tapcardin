<?php

namespace App\Support\Production;

use App\Models\CardProduct;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PlatformSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Estimasi cetak. Mulai = hari kerja pertama setelah lunas. Tiap baris kartu memakai kapasitas harian
 * (sisa = kapasitas − kartu yang sudah dijadwalkan pesanan lain pada hari itu) dan pindah ke hari kerja berikutnya
 * bila kapasitas habis. Selesai cetak = hari cetak terakhir item + lead time (hari kerja): min = `print_min_days`,
 * maks = `print_max_days` (janji ke pemesan). Lanyard: lead time dari config, tanpa kapasitas.
 *
 * Jadwal disimpan saat lunas (order_items.print_* + order_item_schedules) dan tidak dihitung ulang untuk pesanan lama.
 */
final class PrintSchedule
{
    private const MAX_IDLE_DAYS = 3650;

    /**
     * Kunci advisory PostgreSQL (tingkat transaksi) untuk pemesanan kapasitas cetak harian. Diambil lewat
     * `pg_advisory_xact_lock` di transaksi pembayaran sebelum membaca kartu terjadwal dan menyimpan
     * order_item_schedules, sehingga dua pesanan yang lunas bersamaan diserialkan dan tidak memakai kapasitas
     * yang sama. Tidak bergantung pada baris pengaturan apa pun (instalasi baru belum punya
     * `production.cards_per_day`). Dilepas otomatis saat commit/rollback. Nilai tetap: jangan dipakai ulang
     * untuk kunci lain. 0x7461705F70726E74 = "tap_prnt".
     */
    public const CAPACITY_LOCK_KEY = 0x7461705F70726E74;

    /**
     * Hitung jadwal (murni, tanpa DB).
     *
     * @param  list<array{key:int|string, kind:string, quantity:int, min_days:int, max_days:int}>  $rows
     * @param  array<string, int>  $booked  kartu yang sudah terjadwal per hari (Y-m-d) dari pesanan lain
     * @return array{items: array<string, array{starts_on:string, last_day:string, eta_min:string, eta_max:string, delay_days:int, allocations:array<string,int>}>, eta_min: ?string, eta_max: ?string, delay_days: int, note: ?string}
     */
    public static function plan(array $rows, string $start, array $booked, int $capacity, WorkingCalendar $cal): array
    {
        $capacity = max(1, $capacity);
        $startDay = WorkingCalendar::date($start);
        $planned = [];
        $items = [];

        foreach ($rows as $row) {
            $key = (string) $row['key'];
            if ($row['kind'] !== 'card') {
                $items[$key] = self::item($cal, $startDay, $startDay, [], $row);

                continue;
            }

            $remaining = (int) $row['quantity'];
            $day = $startDay;
            $alloc = [];
            $last = $startDay;
            $idle = 0;
            while ($remaining > 0) {
                $ymd = $day->format('Y-m-d');
                $free = max(0, $capacity - ($booked[$ymd] ?? 0) - ($planned[$ymd] ?? 0));
                $take = min($free, $remaining);
                if ($take > 0) {
                    $alloc[$ymd] = ($alloc[$ymd] ?? 0) + $take;
                    $planned[$ymd] = ($planned[$ymd] ?? 0) + $take;
                    $remaining -= $take;
                    $last = $day;
                    $idle = 0;
                } elseif (++$idle > self::MAX_IDLE_DAYS) {
                    throw new RuntimeException('Kapasitas cetak tidak mencukupi untuk menjadwalkan pesanan.');
                }
                if ($remaining > 0) {
                    $day = $cal->nextWorkingDay($day);
                }
            }
            $first = WorkingCalendar::date(array_key_first($alloc));
            $items[$key] = self::item($cal, $first, $last, $alloc, $row, $startDay);
        }

        return self::summary($items);
    }

    /** @return array{starts_on:string, last_day:string, eta_min:string, eta_max:string, delay_days:int, allocations:array<string,int>} */
    private static function item(WorkingCalendar $cal, CarbonImmutable $first, CarbonImmutable $last, array $alloc, array $row, ?CarbonImmutable $nominal = null): array
    {
        $nominal ??= $first;

        return [
            'starts_on' => $first->format('Y-m-d'),
            'last_day' => $last->format('Y-m-d'),
            'eta_min' => $cal->addWorkingDays($last, (int) $row['min_days'])->format('Y-m-d'),
            'eta_max' => $cal->addWorkingDays($last, (int) $row['max_days'])->format('Y-m-d'),
            'delay_days' => $last->gt($nominal) ? $cal->workingDaysBetween($nominal, $last) : 0,
            'allocations' => $alloc,
        ];
    }

    /** @return array{items: array, eta_min: ?string, eta_max: ?string, delay_days: int, note: ?string} */
    private static function summary(array $items): array
    {
        $min = $items ? min(array_column($items, 'eta_min')) : null;
        $max = $items ? max(array_column($items, 'eta_max')) : null;
        $delay = $items ? max(array_column($items, 'delay_days')) : 0;

        return [
            'items' => $items, 'eta_min' => $min, 'eta_max' => $max, 'delay_days' => $delay,
            'note' => $delay > 0 ? "Kapasitas cetak harian sedang terisi, jadi estimasi cetak mundur {$delay} hari kerja." : null,
        ];
    }

    /** Kapasitas kartu per hari kerja: pengaturan platform, lalu config. */
    public static function capacity(): int
    {
        return (int) PlatformSetting::get('production.cards_per_day', config('production.cards_per_day', 200));
    }

    /** Jumlah kartu terjadwal per hari (Y-m-d) mulai $from, dari pesanan yang belum dibatalkan. @return array<string,int> */
    public static function bookedFrom(string $from): array
    {
        return DB::table('order_item_schedules as s')
            ->join('order_items as i', 'i.id', '=', 's.order_item_id')
            ->join('orders as o', 'o.id', '=', 'i.order_id')
            ->where('o.status', '!=', 'cancelled')
            ->where('s.day', '>=', $from)
            ->groupBy('s.day')
            ->selectRaw('s.day AS day, SUM(s.quantity) AS booked')
            ->pluck('booked', 'day')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Pratinjau (tanpa menyimpan) untuk checkout & pesanan yang belum lunas: mulai dari sekarang.
     *
     * @param  iterable<array{key:int|string, kind:string, quantity:int, card_product_id?:?int}>  $specs
     */
    public static function preview(iterable $specs, ?CarbonImmutable $paidAt = null): array
    {
        $cal = WorkingCalendar::fromConfig();
        $start = $cal->nextWorkingDay(($paidAt ?? CarbonImmutable::now('Asia/Jakarta'))->startOfDay());

        return self::plan(self::rows($specs), $start->format('Y-m-d'), self::bookedFrom($start->format('Y-m-d')), self::capacity(), $cal);
    }

    /**
     * Saat pesanan lunas: hitung jadwal dan simpan (kolom print_* item + pemesanan kapasitas per hari).
     * Wajib dipanggil di dalam transaksi pembayaran: kunci advisory kapasitas (CAPACITY_LOCK_KEY) diambil di sini
     * dan baru lepas saat transaksi itu selesai. Idempoten: pesanan yang item-nya sudah punya jadwal tidak dipesan lagi.
     */
    public static function commit(Order $order): void
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('PrintSchedule::commit harus dipanggil di dalam transaksi.');
        }
        self::lockCapacity();

        $items = $order->items()->get();
        if (DB::table('order_item_schedules')->whereIn('order_item_id', $items->pluck('id'))->exists()) {
            return;
        }

        $cal = WorkingCalendar::fromConfig();
        $paid = CarbonImmutable::instance($order->paid_at ?? now())->setTimezone('Asia/Jakarta');
        $start = $cal->nextWorkingDay($paid->startOfDay());
        $plan = self::plan(self::rows(self::specs($items)), $start->format('Y-m-d'), self::bookedFrom($start->format('Y-m-d')), self::capacity(), $cal);

        foreach ($items as $item) {
            $p = $plan['items'][(string) $item->id];
            $item->forceFill([
                'print_starts_on' => $p['starts_on'], 'print_eta_min' => $p['eta_min'], 'print_eta_max' => $p['eta_max'], 'print_delay_days' => $p['delay_days'],
            ])->save();
            foreach ($p['allocations'] as $day => $qty) {
                DB::table('order_item_schedules')->insert(['order_item_id' => $item->id, 'day' => $day, 'quantity' => $qty, 'created_at' => now()]);
            }
        }
    }

    /** Serialkan pemesanan kapasitas cetak sampai transaksi berjalan selesai (PostgreSQL). */
    private static function lockCapacity(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(?)', [self::CAPACITY_LOCK_KEY]);
        }
    }

    /**
     * Jadwal untuk tampilan pesanan: nilai tersimpan bila sudah lunas, pratinjau bila belum.
     *
     * @return array{items: array<string, array{starts_on:?string, eta_min:?string, eta_max:?string, delay_days:int, estimated:bool}>, eta_min: ?string, eta_max: ?string, note: ?string}
     */
    public static function forOrder(Order $order): array
    {
        $items = $order->items()->get();
        $stored = $items->isEmpty() || $items->every(fn ($i) => $i->print_eta_min !== null);
        // pesanan belum lunas: pratinjau; pesanan dibatalkan / lunas tanpa jadwal (sebelum fitur ini): tidak ada estimasi
        if ($stored || $order->status !== 'pending_payment') {
            if (! $stored) {
                return ['items' => [], 'eta_min' => null, 'eta_max' => null, 'note' => null];
            }
            $out = [];
            foreach ($items as $i) {
                $out[(string) $i->id] = $i->print_eta_min === null ? null : [
                    'starts_on' => $i->print_starts_on?->format('Y-m-d'), 'eta_min' => $i->print_eta_min?->format('Y-m-d'),
                    'eta_max' => $i->print_eta_max?->format('Y-m-d'), 'delay_days' => (int) $i->print_delay_days, 'estimated' => false,
                ];
            }
            $vals = array_filter($out);
            $delay = $vals ? max(array_column($vals, 'delay_days')) : 0;

            return ['items' => $out, 'eta_min' => $vals ? min(array_column($vals, 'eta_min')) : null,
                'eta_max' => $vals ? max(array_column($vals, 'eta_max')) : null,
                'note' => $delay > 0 ? "Kapasitas cetak harian sedang terisi, jadi estimasi cetak mundur {$delay} hari kerja." : null];
        }

        $p = self::preview(self::specs($items));
        $out = [];
        foreach ($p['items'] as $key => $v) {
            $out[(string) $key] = ['starts_on' => $v['starts_on'], 'eta_min' => $v['eta_min'], 'eta_max' => $v['eta_max'], 'delay_days' => $v['delay_days'], 'estimated' => true];
        }

        return ['items' => $out, 'eta_min' => $p['eta_min'], 'eta_max' => $p['eta_max'], 'note' => $p['note']];
    }

    /** @param  iterable<OrderItem>  $items  @return list<array{key:int, kind:string, quantity:int, card_product_id:?int}> */
    public static function specs(iterable $items): array
    {
        return collect($items)->map(fn ($i) => ['key' => $i->id, 'kind' => $i->kind, 'quantity' => (int) $i->quantity, 'card_product_id' => $i->card_product_id])->values()->all();
    }

    /**
     * Baris hitung dari spesifikasi item (pesanan atau baris quote): lead time dari produk kartu, atau config untuk lanyard.
     *
     * @param  iterable<mixed>  $specs
     * @return list<array{key:int|string, kind:string, quantity:int, min_days:int, max_days:int}>
     */
    public static function rows(iterable $specs): array
    {
        $specs = collect($specs)->map(fn ($s) => is_array($s) ? $s : $s->toArray());
        $products = CardProduct::whereIn('id', $specs->pluck('card_product_id')->filter()->unique())->get()->keyBy('id');

        return $specs->map(function (array $s) use ($products) {
            $p = $products->get($s['card_product_id'] ?? null);
            $card = ($s['kind'] ?? 'card') === 'card';

            return [
                'key' => $s['key'],
                'kind' => $card ? 'card' : 'lanyard',
                'quantity' => (int) $s['quantity'],
                'min_days' => $card ? (int) ($p?->print_min_days ?? 3) : (int) config('production.lanyard_print_min_days', 3),
                'max_days' => $card ? (int) ($p?->print_max_days ?? 7) : (int) config('production.lanyard_print_max_days', 7),
            ];
        })->values()->all();
    }
}
