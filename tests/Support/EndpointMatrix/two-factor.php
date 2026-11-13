<?php

// Fortify two-factor routes (Phase 6). Statuses are observed behaviour for the matrix fixture, where no
// role has an authenticator app or a pending login: written by hand, never derived from the middleware.
$signedIn = fn (int $status) => [
    'guest' => 302, 'admin' => $status, 'parent' => $status, 'child' => $status,
    'grandparent' => $status, 'guestPreset' => $status, 'noGrants' => $status,
];
$everyone = fn (int $status) => array_fill_keys(
    ['guest', 'admin', 'parent', 'child', 'grandparent', 'guestPreset', 'noGrants'],
    $status,
);

return [
    // No pending login: the challenge page sends visitors to sign in; signed-in members to home.
    'two-factor.login' => ['method' => 'GET', 'uri' => '/two-factor-challenge', 'expect' => $everyone(302)],
    'two-factor.login.store' => ['method' => 'POST', 'uri' => '/two-factor-challenge', 'data' => ['code' => '000000'], 'expect' => $everyone(302)],
    // The matrix replays with the always-fresh session seam, so the `recent` rule on enable/confirm passes here.
    // The stale refusal (423 confirm_required, or the redirect to /confirm-access) is proven in TwoFactorEndpointsTest.
    'two-factor.enable' => ['method' => 'POST', 'uri' => '/user/two-factor-authentication', 'expect' => $everyone(302)],
    'two-factor.confirm' => ['method' => 'POST', 'uri' => '/user/confirmed-two-factor-authentication', 'data' => ['code' => '000000'], 'expect' => $everyone(302)],
    // An unconfirmed (here: absent) secret is cancelled without any confirmation.
    'two-factor.disable' => ['method' => 'DELETE', 'uri' => '/user/two-factor-authentication', 'expect' => $everyone(302)],
    // Secret and QR exist only while a setup is pending; the fixture has none.
    'two-factor.qr-code' => ['method' => 'GET', 'uri' => '/user/two-factor-qr-code', 'expect' => $signedIn(404)],
    'two-factor.secret-key' => ['method' => 'GET', 'uri' => '/user/two-factor-secret-key', 'expect' => $signedIn(404)],
    // Fortify's plaintext recovery-code listing is closed for everybody.
    'two-factor.recovery-codes' => ['method' => 'GET', 'uri' => '/user/two-factor-recovery-codes', 'expect' => $signedIn(403)],
    // Regeneration needs a confirmed authenticator app; the fixture has none.
    'two-factor.regenerate-recovery-codes' => ['method' => 'POST', 'uri' => '/user/two-factor-recovery-codes', 'expect' => $signedIn(404)],
];
