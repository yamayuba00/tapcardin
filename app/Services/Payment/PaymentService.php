<?php

namespace App\Services\Payment;

use App\Exceptions\ApiError;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\User;
use App\Services\Payment\Gateways\HostedLinkGateway;
use App\Services\Payment\Gateways\SimulatorGateway;

/**
 * Pintu masuk pembayaran: memilih gateway aktif (config/payment.php → PAYMENT_DRIVER) dan aturan harga paket.
 * Kode lain hanya bergantung pada kelas ini & kontrak PaymentGateway — bukan pada penyedia tertentu.
 */
final class PaymentService
{
    public const CYCLES = ['monthly' => 1, 'yearly' => 12];

    public static function gateway(): PaymentGateway
    {
        $driver = (string) config('payment.driver', 'simulator');
        // simulator tidak pernah menerima uang sungguhan
        if (($driver === 'simulator' && app()->isProduction()) || ! in_array($driver, ['simulator', 'link'], true)) {
            throw new ApiError('payment_unavailable', 'Pembayaran online belum dikonfigurasi. Hubungi admin TapCard.', 503);
        }

        return $driver === 'link' ? new HostedLinkGateway((array) config('payment.gateway')) : new SimulatorGateway;
    }

    /** Gateway untuk invoice yang sudah dibuat sebelumnya (kode penyedia tersimpan di invoice). */
    public static function gatewayFor(?string $code): ?PaymentGateway
    {
        if (! $code) {
            return null;
        }
        try {
            $gw = self::gateway();
        } catch (ApiError) {
            return null;
        }

        return $gw->code() === $code ? $gw : null;
    }

    /** Info gateway untuk ditampilkan di halaman paket. */
    public static function info(): ?array
    {
        try {
            $gw = self::gateway();
        } catch (ApiError) {
            return null; // belum dikonfigurasi → halaman paket tetap tampil, checkout menampilkan pesan
        }

        return [
            'code' => $gw->code(), 'name' => $gw->label(), 'simulator' => $gw instanceof SimulatorGateway,
            'method' => $gw instanceof SimulatorGateway ? 'Simulasi (mode pengembangan)' : (string) config('payment.gateway.method_label'),
        ];
    }

    /**
     * Buka (atau pakai ulang) sesi bayar untuk invoice terbuka; pengguna kembali ke $returnUrl setelah membayar
     * (halaman invoice / pesanan, yang lalu menanyakan status ke gateway). @return string URL halaman bayar
     */
    public static function startPayment(Invoice $invoice, User $payer, string $returnUrl): string
    {
        if (! $invoice->payment_url) {
            $gw = self::gateway();
            $pay = $gw->checkout($invoice, $payer, $returnUrl);
            $invoice->update(['payment_provider' => $gw->code(), 'payment_url' => $pay['url'], 'payment_ref' => $pay['ref']]);
        }

        return (string) $invoice->payment_url;
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
