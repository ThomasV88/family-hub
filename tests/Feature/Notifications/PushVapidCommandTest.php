<?php

use Illuminate\Support\Facades\Artisan;

it('generates three env lines with fresh keys and a warning', function () {
    Artisan::call('push:vapid');
    $out = Artisan::output();

    expect($out)->toMatch('/^VAPID_SUBJECT=.+$/m')
        ->toMatch('/^VAPID_PUBLIC_KEY=[A-Za-z0-9_-]{20,}$/m')
        ->toMatch('/^VAPID_PRIVATE_KEY=[A-Za-z0-9_-]{20,}$/m')
        ->toContain('Never commit');

    Artisan::call('push:vapid');
    expect(Artisan::output())->not->toBe($out);
});

it('--check reports configured state and never prints the private key', function () {
    config(['push.subject' => null, 'push.public_key' => null, 'push.private_key' => null]);
    expect(Artisan::call('push:vapid', ['--check' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('not configured');

    config(['push.subject' => 'mailto:t@example.test', 'push.public_key' => 'PUBLICKEYVALUE', 'push.private_key' => 'PRIVATEKEYVALUE']);
    expect(Artisan::call('push:vapid', ['--check' => true]))->toBe(0);
    $out = Artisan::output();
    expect($out)->toContain('PUBLICKEYVALUE')->not->toContain('PRIVATEKEYVALUE');
});
