const base = { occurredAt: '2026-05-12T10:00:00+00:00', actorLabel: 'Anna', group: 'security', module: null, targetLabel: null, meta: {}, ip: '203.0.113.7' };
export const AuditEvents = [
    { id: 11, event: 'two_factor_enabled' },
    { id: 12, event: 'two_factor_disabled' },
    { id: 13, event: 'passkey_added' },
    { id: 14, event: 'passkey_renamed' },
    { id: 15, event: 'passkey_removed' },
    { id: 16, event: 'second_factor_failed', meta: { method: 'totp', context: 'login' } },
    { id: 17, event: 'recovery_code_used' },
    { id: 18, event: 'recovery_codes_regenerated' },
    { id: 19, event: 'second_factor_reset', targetLabel: 'Bob' },
    { id: 20, event: 'security_policy_changed', meta: { setting: 'x', value: true } },
    { id: 21, event: 'security_policy_changed', meta: { setting: 'x', value: false } },
    { id: 22, event: 'reauth_confirmed', meta: { method: 'password' } },
].map((e) => ({ ...base, ...e }));
