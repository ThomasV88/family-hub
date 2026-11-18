<?php

use App\Notifications\InviteNotification;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\File;

function brandMailNotifiable(string $locale): object
{
    return new class($locale)
    {
        public function __construct(public string $locale) {}

        public function getEmailForPasswordReset(): string
        {
            return 'member@example.test';
        }
    };
}

it('renders the invite mail with FamilyHUB in every locale', function (string $locale) {
    $mail = (new InviteNotification(1, 'Jana', $locale, 'fake-token'))->toMail(brandMailNotifiable($locale));

    expect($mail->subject)->toContain('FamilyHUB')
        ->and((string) $mail->render())->toContain('FamilyHUB')->not->toContain('Family Hub');
})->with(['en', 'sk', 'pl']);

it('renders the reset mail with FamilyHUB in every locale', function (string $locale) {
    $mail = (new ResetPasswordNotification('fake-token'))->toMail(brandMailNotifiable($locale));

    expect($mail->subject)->toContain('FamilyHUB')
        ->and((string) $mail->render())->toContain('FamilyHUB')->not->toContain('Family Hub');
})->with(['en', 'sk', 'pl']);

it('keeps the old product name out of every lang file', function () {
    foreach (File::allFiles(base_path('lang')) as $file) {
        expect(file_get_contents($file->getPathname()))->not->toContain('Family Hub', $file->getPathname());
    }
});
