<?php

namespace App\Mail;

use App\Support\Mail\MailLayout;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;

/**
 * Undangan bergabung ke workspace perusahaan. Dikirim langsung (tanpa antrean) agar tautan berisi token
 * tidak ikut tersimpan di tabel jobs. Tata letak sama dengan email notifikasi (mail.layout).
 */
class TenantInvite extends Mailable
{
    public function __construct(
        public string $workspace,
        public string $role,
        public ?string $inviter,
        public string $url,
        public Carbon $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Undangan bergabung ke '.$this->workspace.' di TapCard');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.tenant-invite', text: 'mail.tenant-invite-text', with: ['mail' => $this->body()]);
    }

    /** Data tata letak bersama (lihat MailLayout). */
    public function body(): array
    {
        $until = $this->expiresAt->copy()->timezone('Asia/Jakarta')->locale('id')->translatedFormat('j F Y, H.i').' WIB';

        return [
            'subject' => 'Undangan bergabung ke '.$this->workspace.' di TapCard',
            'preheader' => ($this->inviter ? $this->inviter.' mengundang Anda' : 'Anda diundang').' ke '.$this->workspace.' sebagai '.$this->role.'. Berlaku sampai '.$until.'.',
            'title' => 'Anda diundang bergabung ke '.$this->workspace,
            'tone' => 'default',
            'name' => null,
            'lines' => [
                ($this->inviter ? $this->inviter.' mengundang Anda' : 'Anda diundang').' sebagai **'.$this->role.'** di workspace perusahaan **'.$this->workspace.'**.',
                'Klik tombol di bawah, konfirmasi email Anda, lalu buat kata sandi untuk masuk. Tautan hanya bisa dipakai sekali.',
            ],
            'facts' => array_values(array_filter([
                ['Workspace', $this->workspace], ['Peran', $this->role], $this->inviter ? ['Pengundang', $this->inviter] : null, ['Berlaku sampai', $until],
            ])),
            'items' => [],
            'action' => ['label' => 'Terima undangan', 'url' => $this->url],
            'notes' => ['Jika Anda tidak merasa diundang, abaikan email ini — tidak ada akun yang dibuat tanpa konfirmasi Anda.'],
            'footer' => MailLayout::footer(null, $this->workspace, 'Email ini dikirim karena alamat ini diundang bergabung ke workspace '.$this->workspace.' di TapCard.', null),
        ];
    }
}
