<?php

namespace App\Mail;

use App\Support\Frontend;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent to an invited landlord admin. Carries a single-use link to choose
 * their first password (App\Services\LandlordPasswordSetupService).
 */
class LandlordSetPasswordLinkMail extends TransactionalMail
{
    public function __construct(
        public string $email,
        public string $name,
        public string $token,
        public int $ttlHours,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Set up your '.config('app.name').' admin account');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.landlord-set-password-link', with: [
            'name' => $this->name,
            'url' => Frontend::url('/sa/set-password/'.$this->token),
            'ttlHours' => $this->ttlHours,
        ]);
    }
}
