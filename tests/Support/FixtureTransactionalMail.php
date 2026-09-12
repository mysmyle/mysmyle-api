<?php

namespace Tests\Support;

use App\Mail\TransactionalMail;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Stand-in for the real transactional mailables (built in later phases) so the
 * mail infrastructure — queued base class, shared partial, Frontend::url() —
 * can be exercised without shipping a placeholder in app/.
 * View: tests/Support/views/fixture-mail.blade.php (path added in the test).
 */
class FixtureTransactionalMail extends TransactionalMail
{
    public function __construct(public string $token) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Fixture');
    }

    public function content(): Content
    {
        return new Content(markdown: 'fixture-mail', with: ['token' => $this->token]);
    }
}
