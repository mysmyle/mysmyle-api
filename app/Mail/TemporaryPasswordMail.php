<?php

namespace App\Mail;

use App\Support\Frontend;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent when an admin creates or resets a tenant user account. Carries the
 * one-time temporary password; the recipient must set their own on first
 * sign-in (users.must_change_password).
 */
class TemporaryPasswordMail extends TransactionalMail
{
    public function __construct(
        public string $email,
        public string $name,
        public string $temporaryPassword,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your '.config('app.name').' account');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.temporary-password', with: [
            'name' => $this->name,
            'password' => $this->temporaryPassword,
            'loginUrl' => Frontend::url('/login'),
        ]);
    }
}
