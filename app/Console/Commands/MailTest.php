<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Throwable;

/**
 * Cek konfigurasi SMTP (mis. Sumopod: smtp.sumopod.com:465, MAIL_SCHEME=smtps).
 *   php artisan tapcard:mail-test                 → hanya sambung + login ke server SMTP (tidak mengirim apa pun)
 *   php artisan tapcard:mail-test you@domain.com  → kirim email uji ke alamat itu
 */
class MailTest extends Command
{
    protected $signature = 'tapcard:mail-test {to? : alamat tujuan email uji}';

    protected $description = 'Uji koneksi & login SMTP, opsional kirim email uji';

    public function handle(): int
    {
        $c = config('mail.mailers.'.config('mail.default'));
        $this->line(sprintf('Mailer %s → %s:%s (%s), pengirim %s',
            config('mail.default'), $c['host'] ?? '-', $c['port'] ?? '-', $c['scheme'] ?? 'smtp', config('mail.from.address')));

        try {
            $transport = Mail::mailer()->getSymfonyTransport();
            if ($transport instanceof SmtpTransport) {
                $transport->start(); // TLS + EHLO + AUTH
                $this->info('SMTP: tersambung & login berhasil.');
                $transport->stop();
            }
            if ($to = $this->argument('to')) {
                Mail::raw("Email uji dari TapCard.\n\nJika Anda menerima email ini, pengiriman SMTP sudah berfungsi.", function ($m) use ($to) {
                    $m->to($to)->subject('Uji SMTP TapCard');
                });
                $this->info("Email uji terkirim ke $to.");
            }
        } catch (Throwable $e) {
            $this->error('SMTP gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
