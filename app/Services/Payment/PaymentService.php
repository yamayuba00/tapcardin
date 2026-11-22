<?php

namespace App\Services\Payment;

use App\Actions\Billing\ApplyPayment;
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
    public const CYCLES = ['monthly' => 1, 'semiannual' => 6, 'yearly' => 12];

    public const CYCLE_LABELS = ['monthly' => '1 bulan', 'semiannual' => '6 bulan', 'yearly' => '1 tahun'];

    public static function gateway(): PaymentGateway
    {
        $driver = (string) config('payment.driver', 'simulator');
        // simulator tidak pernah menerima uang sungguhan: hanya local/testing — staging/produksi gagal tertutup
        if (($driver === 'simulator' && ! self::simulatorAllowed()) || ! in_array($driver, ['simulator', 'link'], true)) {
            throw new ApiError('payment_unavailable', 'Pembayaran online belum dikonfigurasi. Hubungi admin TapCard.', 503);
        }

        return $driver === 'link' ? new HostedLinkGateway((array) config('payment.gateway')) : new SimulatorGateway;
    }

    public static function simulatorAllowed(): bool
    {
        return app()->environment(['local', 'testing']);
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
     * (halaman invoice / pesanan, yang lalu menanyakan status ke gateway). Link basi / gagal tidak pernah diberikan:
     * status link lama ditanyakan dulu (bisa saja sudah dibayar tapi webhook hilang), lalu dibuat link baru.
     *
     * @return string URL halaman bayar
     */
    public static function startPayment(Invoice $invoice, User $payer, string $returnUrl): string
    {
        $gw = self::gateway();
        $live = $invoice->payment_provider === $gw->code() ? $invoice->livePaymentUrl() : null;
        $old = $invoice->payment_ref && $invoice->payment_provider === $gw->code() ? $gw->fetchStatus($invoice) : null;
        if ($old?->status === PaymentNotification::PAID) {
            app(ApplyPayment::class)->handle($old);
            throw new ApiError('invoice_paid', 'Pembayaran untuk invoice ini sudah kami terima. Muat ulang halaman.', 409);
        }
        if ($live && $old?->status !== PaymentNotification::FAILED) {
            return $live;
        }

        $invoice->update(['payment_attempts' => $invoice->payment_attempts + 1]);
        $pay = $gw->checkout($invoice, $payer, $returnUrl);
        // link tidak boleh hidup lebih lama dari invoice-nya (invoice checkout berumur 30 menit, link gateway per jam)
        $expires = $pay['expires_at'] ?? null;
        if ($expires && $invoice->expires_at && $invoice->expires_at->lt($expires)) {
            $expires = $invoice->expires_at;
        }
        $invoice->update(['payment_provider' => $gw->code(), 'payment_url' => $pay['url'], 'payment_ref' => $pay['ref'], 'payment_url_expires_at' => $expires]);

        return (string) $invoice->payment_url;
    }

    public static function price(Plan $plan, string $cycle): int
    {
        return $plan->priceFor($cycle);
    }

    /** Pendapatan per bulan dari satu langganan (untuk MRR). */
    public static function monthly(Plan $plan, string $cycle): float
    {
        return $plan->priceFor($cycle) / (self::CYCLES[$cycle] ?? 1);
    }

    /** Hemat langganan tahunan dibanding 12× bulanan (rupiah). */
    public static function yearlySaving(Plan $plan): int
    {
        return max(0, (int) $plan->price_monthly * 12 - (int) $plan->price_yearly);
    }
}
