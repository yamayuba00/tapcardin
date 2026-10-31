<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Kode 6 digit untuk verifikasi pendaftaran (`register`) atau reset password (`reset`). */
class AuthCodeMail extends Mailable
{
    use Queueable;

    public const COPY = [
        'register' => ['Kode verifikasi pendaftaran TapCard', 'Masukkan kode ini untuk menyelesaikan pendaftaran akun TapCard Anda.'],
        'reset' => ['Kode reset password TapCard', 'Masukkan kode ini untuk membuat password baru akun TapCard Anda.'],
    ];

    public function __construct(public readonly string $code, public readonly string $purpose = 'register') {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: self::COPY[$this->purpose][0].": {$this->code}");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.auth-code', with: ['intro' => self::COPY[$this->purpose][1]]);
    }
}
