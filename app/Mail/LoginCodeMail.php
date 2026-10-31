<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class LoginCodeMail extends Mailable
{
    use Queueable;

    public function __construct(public readonly string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Kode masuk TapCard: {$this->code}");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.login-code');
    }
}
