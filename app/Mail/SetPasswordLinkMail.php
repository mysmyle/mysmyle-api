<?php

namespace App\Mail;

use App\Support\Frontend;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent to a newly provisioned tenant admin. Carries a single-use link to
 * choose their first password (App\Services\PasswordSetupService).
 */
class SetPasswordLinkMail extends TransactionalMail
{
    public function __construct(
        public string $email,
        public string $name,
        public string $clinicName,
        public string $token,
        public int $ttlHours,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Set up your '.config('app.name').' account');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.set-password-link', with: [
            'name' => $this->name,
            'clinicName' => $this->clinicName,
            'url' => Frontend::url('/set-password/'.$this->token),
            'ttlHours' => $this->ttlHours,
        ]);
    }
}
