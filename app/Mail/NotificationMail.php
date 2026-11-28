<?php

namespace App\Mail;

use App\Support\Mail\EmailContent;
use App\Support\Mail\MailLayout;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email untuk satu notifikasi (lihat EmailContent). Dikirim lewat antrean `notify` setelah transaksi selesai, jadi
 * SMTP lambat/gagal tidak memperlambat atau menggagalkan aksi pengguna. Data sudah ikut di payload (tanpa query ulang).
 * Tampilan: resources/views/mail/layout(.text).blade.php — sama dengan email undangan.
 */
class NotificationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300];

    /**
     * @param  string  $baseUrl  asal tautan tombol (app. atau subdomain workspace)
     * @param  ?string  $workspace  nama workspace perusahaan untuk footer (null = personal / tanpa workspace)
     */
    public function __construct(public string $type, public array $data, public string $recipientName, public string $baseUrl, public ?string $workspace = null)
    {
        $this->onQueue('notify');
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->body()['subject']);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.notification', text: 'mail.notification-text', with: [
            'mail' => $this->body(),
            'name' => $this->recipientName,
        ]);
    }

    /**
     * Isi EmailContent + bagian tata letak (sapaan, footer). Jenis tak dikenal → email generik.
     *
     * @return array{subject:string, preheader:string, title:string, lines:list<string>, facts:list<array{0:string,1:string}>, items:list<array>, action:?array{label:string,url:string}, tone:string, type:string, name:string, footer:array}
     */
    public function body(): array
    {
        $c = EmailContent::for($this->type, $this->data)
            ?? ['subject' => 'Notifikasi TapCard', 'title' => 'Notifikasi TapCard', 'lines' => [], 'action' => null, 'tone' => 'default', 'preheader' => '', 'facts' => [], 'items' => []];
        $c['type'] = $this->type;
        $c['name'] = $this->recipientName;
        $base = rtrim($this->baseUrl, '/');
        if ($c['action']) {
            $c['action']['url'] ??= $base.$c['action']['path']; // email panel memberi URL lengkap
        }
        // jenis yang selalu dikirim (keamanan, peringatan sistem) tidak punya saklar → tanpa tautan pengaturan
        $optional = ! in_array($this->type, EmailContent::ALWAYS, true);
        $c['footer'] = MailLayout::footer($this->recipientName, $this->workspace, EmailContent::reason($this->type), $optional ? $base.'/settings' : null,
            EmailContent::defaultOn($this->type) ? 'Pengaturan notifikasi' : 'Atur email notifikasi');

        return $c;
    }
}
