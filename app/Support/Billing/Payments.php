<?php

namespace App\Support\Billing;

use App\Models\Plan;

/** Pemilih gateway (TAPCARD_PAYMENT_DRIVER) + harga paket per siklus. */
final class Payments
{
    public const CYCLES = ['monthly' => 1, 'yearly' => 12];

    public static function gateway(): PaymentGateway
    {
        $driver = config('services.payments.driver', 'simulator');
        // simulator tidak boleh menerima uang sungguhan
        if ($driver === 'simulator' && app()->isProduction()) {
            $driver = 'midtrans';
        }

        return $driver === 'midtrans'
            ? new MidtransGateway((string) config('services.midtrans.server_key'), (bool) config('services.midtrans.production'))
            : new SimulatorGateway;
    }

    public static function gatewayFor(string $provider): ?PaymentGateway
    {
        return match ($provider) {
            'midtrans' => new MidtransGateway((string) config('services.midtrans.server_key'), (bool) config('services.midtrans.production')),
            default => null,
        };
    }

    public static function price(Plan $plan, string $cycle): int
    {
        return (int) ($cycle === 'yearly' ? $plan->price_yearly : $plan->price_monthly);
    }

    /** Hemat langganan tahunan dibanding 12× bulanan (rupiah). */
    public static function yearlySaving(Plan $plan): int
    {
        return max(0, (int) $plan->price_monthly * 12 - (int) $plan->price_yearly);
    }
}
