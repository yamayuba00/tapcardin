<?php

namespace App\Support;

use App\Models\Order;

/**
 * Penilaian risiko pesanan setelah dibayar, sebelum produksi. Pesanan yang bertanda risiko harus ditinjau
 * (setujui / tolak) oleh staf dengan izin `orders.review`. Semua ambang ada di config/tapcard.php (`risk`).
 */
final class OrderRisk
{
    /** @return array{score:int, flags:list<array{code:string,label:string,severity:int}>} */
    public static function assess(Order $order): array
    {
        $cfg = config('tapcard.risk');
        $order->loadMissing(['items', 'user']);
        $flags = [];
        $add = function (string $code, string $label, int $severity) use (&$flags) {
            $flags[] = compact('code', 'label', 'severity');
        };
        $rp = fn (int $n) => 'Rp '.number_format($n, 0, ',', '.');
        $total = (int) $order->total;

        if ($total >= (int) $cfg['high_value']) {
            $add('high_value', 'Nilai pesanan besar ('.$rp($total).')', 2);
        }

        $user = $order->user;
        if ($user && $user->created_at?->gt(now()->subDays((int) $cfg['new_account_days'])) && $total >= (int) $cfg['new_account_value']) {
            $add('new_account', "Akun baru (< {$cfg['new_account_days']} hari) memesan ".$rp($total), 2);
        }

        if ($order->address_line && $order->postal_code) {
            $key = self::addressKey($order);
            $same = Order::withoutGlobalScopes()->where('id', '!=', $order->id)->where('postal_code', $order->postal_code)
                ->where('created_at', '>=', now()->subDays(30))->where('status', '!=', 'cancelled')->whereNotNull('address_line')
                ->get(['id', 'user_id', 'address_line', 'postal_code'])
                ->filter(fn (Order $o) => self::addressKey($o) === $key);
            if ($same->count() + 1 >= (int) $cfg['same_address_orders']) {
                $add('same_address_orders', ($same->count() + 1).' pesanan ke alamat yang sama dalam 30 hari', 2);
            }
            if ($same->isNotEmpty() && $same->pluck('user_id')->push($order->user_id)->unique()->count() >= 2) {
                $add('same_address_many_accounts', 'Beberapa akun berbeda memesan ke alamat yang sama', 3);
            }
        }

        if ($order->user_id && Order::withoutGlobalScopes()->where('user_id', $order->user_id)->where('created_at', '>=', now()->subDay())->count() >= (int) $cfg['same_user_burst']) {
            $add('order_burst', 'Banyak pesanan dari akun yang sama dalam 24 jam', 2);
        }

        $texts = self::textsOf($order);
        foreach ((array) $cfg['blocked_terms'] as $term) {
            if ($term !== '' && collect($texts)->contains(fn (string $t) => mb_stripos($t, $term) !== false)) {
                $add('blocked_term', "Desain memuat kata yang perlu dicek: “{$term}”", 3);
            }
        }

        return ['score' => array_sum(array_column($flags, 'severity')), 'flags' => $flags];
    }

    /** Semua teks yang akan tercetak (teks layer + data profil pada snapshot), untuk diperiksa reviewer. @return list<string> */
    public static function textsOf(Order $order): array
    {
        $order->loadMissing('items');
        $out = [];
        foreach ($order->items as $item) {
            $snap = (array) $item->design_snapshot;
            foreach (['front', 'back'] as $side) {
                foreach ((array) ($snap[$side]['layers'] ?? []) as $layer) {
                    if (($layer['type'] ?? null) === 'text' && is_string($layer['text'] ?? null)) {
                        $out[] = trim($layer['text']);
                    }
                }
            }
            foreach ((array) ($snap['vars'] ?? []) as $v) {
                if (is_string($v)) {
                    $out[] = trim($v);
                }
            }
        }

        return array_slice(array_values(array_unique(array_filter($out, fn ($t) => $t !== ''))), 0, 100);
    }

    private static function addressKey(Order $o): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $o->address_line.$o->postal_code));
    }
}
