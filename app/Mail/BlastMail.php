<?php

namespace App\Mail;

use App\Support\Blast\BlastMarkdown;
use App\Support\Blast\BlastUnsubscribe;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * Email blast promo untuk satu penerima. Dikirim dari job SendBlastBatch (antrean `mail`), jadi mailable ini tidak
 * diantrekan lagi. Header List-Unsubscribe + List-Unsubscribe-Post (one-click, RFC 8058) wajib untuk email massal.
 */
class BlastMail extends Mailable
{
    public string $unsubscribe;

    public function __construct(public string $blastSubject, public ?string $preheader, public string $bodyMarkdown, public string $email, public ?string $blastId = null, public bool $test = false)
    {
        $this->unsubscribe = BlastUnsubscribe::url($email, $blastId);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: ($this->test ? '[UJI] ' : '').$this->blastSubject);
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->unsubscribe.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.blast', text: 'mail.blast-text', with: [
            'subject' => $this->blastSubject,
            'preheader' => $this->preheader,
            'bodyHtml' => BlastMarkdown::html($this->bodyMarkdown),
            'bodyText' => BlastMarkdown::text($this->bodyMarkdown),
            'unsubscribeUrl' => $this->unsubscribe,
            'logoUrl' => self::logoUrl(),
            'address' => (string) config('tapcard.blast.address'),
        ]);
    }

    /** Logo email (URL absolut) bila berkas public/email/tapcard-logo.png ada; null → wordmark teks. */
    public static function logoUrl(): ?string
    {
        if (! is_file(public_path('email/tapcard-logo.png'))) {
            return null;
        }

        return rtrim((string) (config('tapcard.mail.asset_url') ?: config('app.url')), '/').'/email/tapcard-logo.png';
    }
}
