<?php

use App\Models\Invitation;
use App\Notifications\InviteNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;

function pendingInvitation(string $locale = 'sk'): Invitation
{
    return Invitation::create([
        'email' => 'eva@example.test', 'token_hash' => hash('sha256', 'tok'), 'preset' => 'child', 'locale' => $locale,
        'expires_at' => now()->addDays(7),
    ]);
}

it('is queued and renders in the invitation locale with the inviter and the link', function () {
    $inv = pendingInvitation('sk');
    $notification = new InviteNotification($inv->id, 'Mira', 'sk', 'plaintoken');

    expect($notification)->toBeInstanceOf(ShouldQueue::class);
    $mail = $notification->toMail(new AnonymousNotifiable);

    expect($mail->subject)->toBe(trans('mail.invite_subject', [], 'sk'))
        ->and($mail->introLines[0])->toContain('Mira')
        ->and($mail->actionUrl)->toEndWith('/invite/plaintoken')
        ->and($mail->actionText)->toBe(trans('mail.invite_action', [], 'sk'));
    expect(trans('mail.invite_subject', [], 'sk'))->not->toBe(trans('mail.invite_subject', [], 'en'));
});

it('flags the invitation when the queued mail fails', function () {
    $inv = pendingInvitation();
    (new InviteNotification($inv->id, 'Mira', 'sk', 'plaintoken'))->failed(new RuntimeException('smtp down'));

    expect($inv->refresh()->mail_failed_at)->not->toBeNull();
});

it('falls back to English for an unknown locale', function () {
    $mail = (new InviteNotification(1, 'Mira', 'de', 't'))->toMail(new AnonymousNotifiable);
    expect($mail->subject)->toBe(trans('mail.invite_subject', [], 'en'));
});
