<?php

namespace App\Notifications\Concerns;

use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\HtmlString;

/** Shared helpers for mails that need a Markdown part (escaped) and an explicit real text/plain part (unescaped). */
trait PlainTextMail
{
    /** Backslash-escape ASCII punctuation so a name or custom title cannot become a link, image or emphasis. */
    private static function plain(string $text): string
    {
        return preg_replace('/([!-\/:-@\[-`{-~])/', '\\\\$1', $text) ?? '';
    }

    /** Attaches the Markdown HTML view and the given unescaped text/plain alternative. */
    private static function withPlainPart(MailMessage $mail, string $plain): MailMessage
    {
        $data = $mail->data();
        $theme = $mail->theme;

        return $mail->view([
            'html' => fn () => app(Markdown::class)->theme($theme ?: app(Markdown::class)->getTheme())->render('notifications::email', $data),
            'text' => new HtmlString($plain),
        ], $data);
    }
}
