<?php

// Every duration, cap and limit below is [ASSUMED] tune after the first real run (research A8).
return [
    'codex' => [
        'binary' => env('AI_CODEX_BINARY', '/opt/codex/bin/codex'),
        'version' => '0.169.0',
        // null = omit -m and record 'default' (research A4).
        'model' => env('AI_CODEX_MODEL'),
        'home' => env('CODEX_HOME', '/codex'),
    ],

    'work_dir' => env('AI_WORK_DIR', '/ai-work'),

    // [ASSUMED] tune after the first real run (research A8)
    'timeouts' => [
        'process' => 300,
        'idle' => 120,
        'job' => 330,
        'login' => 10,
        'abort_check_seconds' => 2,
        'queued_stale_hours' => 6,
    ],

    // [ASSUMED] tune after the first real run (research A8)
    'caps' => [
        'stdout' => 2097152,
        'stderr' => 262144,
        'result' => 262144,
    ],

    // [ASSUMED] tune after the first real run (research A8)
    'pdf' => [
        'max_pages' => 10,
        'view_max_pages' => 50,
        'scale' => 1800,
        'timeout' => 20,
        'info_timeout' => 5,
        'ulimit_kb' => 786432,
    ],

    // [ASSUMED] case-insensitive substrings of an auth failure; frozen in 08-22 from a real transcript.
    'auth_failure_patterns' => ['401', 'unauthorized', 'sign in', 'log in again', 'login again', 'refresh token'],

    // Variables that would switch the CLI to per-token billing; the worker asserts they are unset.
    'forbidden_env' => [
        'OPENAI_API_KEY', 'CODEX_API_KEY', 'ANTHROPIC_API_KEY', 'OPENAI_BASE_URL', 'OPENAI_ORG_ID',
        'CODEX_ACCESS_TOKEN', 'CODEX_AGENT_IDENTITY',
    ],

    // Name patterns of foreign secrets the ai-worker must never receive (ai:preflight reports forbidden_secret).
    // APP_KEY is covered separately through config('app.key').
    'forbidden_env_patterns' => [
        '/^(MAIL|GOOGLE|VAPID|AWS|STRIPE)_/', '/_API_KEY$/', '/_SECRET$/', '/^APP_PREVIOUS_KEYS$/',
    ],

    // Records one proposal may create when its entries are accepted (a proposal holds at most 100 entries).
    'max_accepts_per_proposal' => 100,

    'login_stale_minutes' => 15,
    'call_retention_months' => 12,
    'queue' => 'ai',
];
