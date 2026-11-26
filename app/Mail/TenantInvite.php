<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;

/**
 * Undangan bergabung ke workspace perusahaan. Dikirim langsung (tanpa antrean) agar tautan berisi token
 * tidak ikut tersimpan di tabel jobs.
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
        return new Content(view: 'mail.tenant-invite');
    }
}
