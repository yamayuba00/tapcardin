<?php

namespace App\Console\Commands;

use App\Models\Card;
use App\Support\CardPeriod;
use App\Support\Notify;
use Illuminate\Console\Command;

/** Pengingat masa pakai kartu penjualan langsung: H-30, H-7, H-1, dan saat tenggang habis. Sekali per periode. */
class CardPeriods extends Command
{
    protected $signature = 'tapcard:card-periods';

    protected $description = 'Pengingat masa pakai kartu penjualan langsung (H-30, H-7, H-1, tenggang habis)';

    public function handle(): int
    {
        $now = now();
        $sent = 0;
        foreach ([30 => 'h30', 7 => 'h7', 1 => 'h1'] as $days => $stage) {
            $this->eligible()->whereBetween('valid_until', [$now->copy()->addDays($days), $now->copy()->addDays($days + 1)])
                ->each(function (Card $card) use ($stage, $days, &$sent) {
                    $sent += $this->remind($card, $stage, $days);
                });
        }
        $graceDays = CardPeriod::graceDays();
        $this->eligible()->whereBetween('valid_until', [$now->copy()->subDays($graceDays + 1), $now->copy()->subDays($graceDays)])
            ->each(function (Card $card) use (&$sent) {
                $sent += $this->remind($card, 'grace_end', 0);
            });
        $this->info("Pengingat masa pakai terkirim: {$sent}");

        return self::SUCCESS;
    }

    private function eligible()
    {
        return Card::acrossTenants()->where('form', Card::PHYSICAL)->whereNotNull('valid_until')
            ->whereNotNull('holder_user_id')->whereNotIn('status', ['revoked']);
    }

    private function remind(Card $card, string $stage, int $days): int
    {
        $key = $card->valid_until->toDateString().':'.$stage;
        $sent = $card->period_reminders ?? [];
        if (in_array($key, $sent, true)) {
            return 0;
        }
        Notify::user($card->holder_user_id, $card->tenant_id, 'card_period', [
            'stage' => $stage, 'days' => $days, 'card' => $card->card_token, 'label' => $card->formattedShortCode(),
            'until' => $card->valid_until->toIso8601String(), 'renew_price' => CardPeriod::renewalPrice(),
        ]);
        $card->forceFill(['period_reminders' => [...$sent, $key]])->save();

        return 1;
    }
}
