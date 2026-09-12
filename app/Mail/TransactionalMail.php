<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Base for every email the app sends (all transactional: provisioning welcome,
 * set-password link, temporary password). Queued and serialized consistently —
 * a running queue worker delivers them, same as tenant provisioning. One place
 * to add cross-cutting behaviour (tags, headers, retry policy) later.
 */
abstract class TransactionalMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;
}
