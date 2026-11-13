<?php

// Passkey routes (Phase 6). No fixture role has a passkey or a pending login, so these record the observed
// outcome for "member without passkeys" and "no pending sign-in". Hand-written, never derived from middleware.
$signedIn = fn (int $status) => [
    'guest' => 302, 'admin' => $status, 'parent' => $status, 'child' => $status,
    'grandparent' => $status, 'guestPreset' => $status, 'noGrants' => $status,
];
$everyone = fn (int $status) => array_fill_keys(
    ['guest', 'admin', 'parent', 'child', 'grandparent', 'guestPreset', 'noGrants'],
    $status,
);

return [
    // The matrix replays with the always-fresh session seam, so options and store record the fresh outcome here.
    // The stale 423 (no options stored, no passkey row) is proven in PasskeyManagementTest.
    'passkeys.options' => ['method' => 'GET', 'uri' => '/settings/security/passkeys/options', 'expect' => $signedIn(200)],
    // Empty body: validation failure for a signed-in member (never reaches the ceremony).
    'passkeys.store' => ['method' => 'POST', 'uri' => '/settings/security/passkeys', 'data' => [], 'expect' => $signedIn(302)],
    // Nobody owns passkey 999999: a missing and a foreign passkey look the same. Route-model binding runs before
    // the access rule, which used to give a guest a 404; the id is now resolved in the controller (06-REVIEW IN-02), so a
    // guest gets the same 302 for any id.
    'passkeys.update' => ['method' => 'PATCH', 'uri' => '/settings/security/passkeys/999999', 'data' => ['name' => 'x'], 'expect' => $signedIn(404)],
    'passkeys.destroy' => ['method' => 'DELETE', 'uri' => '/settings/security/passkeys/999999', 'expect' => $signedIn(404)],
    // No pending sign-in: guests go to /login; signed-in members are sent home by the guest rule.
    'two-factor.passkey.options' => ['method' => 'GET', 'uri' => '/two-factor-challenge/passkey/options', 'expect' => $everyone(302)],
    'two-factor.passkey.store' => ['method' => 'POST', 'uri' => '/two-factor-challenge/passkey', 'data' => [], 'expect' => $everyone(302)],
    // The fixture roles have no passkeys: options is a 404; the empty store body fails validation first (302).
    'confirm-access.passkey.options' => ['method' => 'GET', 'uri' => '/confirm-access/passkey/options', 'expect' => $signedIn(404)],
    'confirm-access.passkey.store' => ['method' => 'POST', 'uri' => '/confirm-access/passkey', 'data' => [], 'expect' => $signedIn(302)],
];
