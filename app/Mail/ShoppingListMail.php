<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\HtmlString;

/**
 * One plain-text e-mail of a shopping list to one address. Carries a finished subject and body snapshot (built in the
 * sender's language at request time); no HTML part, no Reply-To, no member address.
 */
class ShoppingListMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $mailSubject, public readonly string $mailBody)
    {
        // Raw text: "&" and "<" in names must reach the recipient as typed (research Pitfall 8).
        $this->text(new HtmlString($mailBody));
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject);
    }
}
