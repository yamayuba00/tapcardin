<?php

namespace App\Mail;

use App\Support\Mail\EmailContent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email untuk satu notifikasi (lihat EmailContent). Dikirim lewat antrean `notify` setelah transaksi selesai, jadi
 * SMTP lambat/gagal tidak memperlambat atau menggagalkan aksi pengguna. Data sudah ikut di payload (tanpa query ulang).
 */
class NotificationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300];

    /** @param  string  $baseUrl  asal tautan tombol (app. atau subdomain workspace) */
    public function __construct(public string $type, public array $data, public string $recipientName, public string $baseUrl)
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

    /** @return array{subject:string, title:string, lines:list<string>, action:?array{label:string,url:string}, tone:string} */
    public function body(): array
    {
        $c = EmailContent::for($this->type, $this->data) ?? ['subject' => 'Notifikasi TapCard', 'title' => 'Notifikasi TapCard', 'lines' => [], 'action' => null, 'tone' => 'default'];
        $c['type'] = $this->type;
        if ($c['action']) {
            $c['action']['url'] = rtrim($this->baseUrl, '/').$c['action']['path'];
        }

        return $c;
    }
}
