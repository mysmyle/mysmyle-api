<?php

namespace Tests\Feature;

use App\Mail\TransactionalMail;
use App\Support\Frontend;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;
use Tests\Support\FixtureTransactionalMail;
use Tests\TestCase;

class MailInfrastructureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        view()->addLocation(base_path('tests/Support/views'));
    }

    public function test_frontend_url_builds_spa_links(): void
    {
        config(['app.frontend_url' => 'https://app.example.com/']);

        $this->assertSame('https://app.example.com', Frontend::url());
        $this->assertSame('https://app.example.com/set-password/abc', Frontend::url('/set-password/abc'));
        $this->assertSame('https://app.example.com/set-password/abc', Frontend::url('set-password/abc'));
    }

    public function test_transactional_mail_is_queued(): void
    {
        $this->assertInstanceOf(ShouldQueue::class, new FixtureTransactionalMail('tok'));
        $this->assertInstanceOf(TransactionalMail::class, new FixtureTransactionalMail('tok'));

        Mail::fake();
        Mail::to('someone@test.test')->send(new FixtureTransactionalMail('tok'));

        Mail::assertQueued(FixtureTransactionalMail::class, fn ($mail) => $mail->hasTo('someone@test.test'));
    }

    public function test_a_transactional_mail_renders_with_the_shared_notice_and_a_frontend_link(): void
    {
        config(['app.frontend_url' => 'https://app.example.com']);

        $rendered = (new FixtureTransactionalMail('xyz789'))->render();

        $this->assertStringContainsString('https://app.example.com/set-password/xyz789', $rendered);
        $this->assertStringContainsString('automated message from '.config('app.name'), $rendered);
    }
}
