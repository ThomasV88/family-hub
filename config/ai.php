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

    // Registry order of KNOWN agents (not the run order; that is the admin setting).
    'agents' => ['codex', 'copilot'],

    // Zone of the bulk start hour and the Copilot daily ceiling (the CLIs themselves run with TZ=UTC).
    'local_timezone' => 'Europe/Bratislava',

    'copilot' => [
        'binary' => env('AI_COPILOT_BINARY', '/opt/copilot/bin/copilot'),
        // Also pinned in docker/app/Dockerfile and docs/ops/server.md. UNVERIFIED live: `--available-tools` is passed with no
        // value to mean "no tools"; the --deny-tool flags are the layer that is tested. Re-check on every version bump.
        'version' => '1.0.112',
        // null = fallback only; the admin model setting wins.
        'model' => env('AI_COPILOT_MODEL'),
        'home' => env('COPILOT_HOME', '/copilot'),
        'cache' => env('COPILOT_CACHE_HOME', '/opt/copilot/cache'),
        // [PROVISIONAL] cost guard per session, owner confirms in 09-20.
        'max_ai_credits' => 60,
        // [PROVISIONAL] AUP guard: CLI spawns per local day, 0 = unlimited.
        'daily_runs' => 30,
        // [PROVISIONAL] CLI spawns per local day kept free for interactive runs: bulk stops at daily_runs minus this.
        // The daily count is of spawns (a repair call is a second one), not of call-log rows.
        'bulk_reserve' => 10,
        'repair_min_seconds' => 45,
        // Seconds without output before a Copilot run is killed as idle; null = none, the total time bound alone applies.
        // The CLI runs with --stream off and --log-level none, so it prints nothing until its single final answer; an idle
        // bound shorter than a slow answer would kill a healthy run.
        'idle_timeout' => null,
        // First entry VERIFIED, the rest ASSUMED until the live check L4.
        'auth_failure_patterns' => [
            'no authentication information found', 'not authenticated', 'authentication failed',
            'bad credentials', 'unauthorized', 'login again',
        ],
        // [ASSUMED] marker keys of the signed-in account in config.json (research A2); correct live (L1).
        'login_keys' => ['logged_in_users', 'last_logged_in_user', 'loggedInUsers', 'lastLoggedInUser'],
    ],

    'work_dir' => env('AI_WORK_DIR', '/ai-work'),

    // [ASSUMED] tune after the first real run (research A8)
    'timeouts' => [
        'process' => 300,
        'idle' => 120,
        'job' => 660,
        // Shortest per-call timeout the attempt loop will start (seconds); 'job' covers up to two attempts plus one repair each.
        'min_call' => 30,
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
        // Pages the AI reads (owner decision 2026-11-16: 20). The viewer limit view_max_pages is a separate setting.
        'max_pages' => 20,
        'view_max_pages' => 50,
        'scale' => 1800,
        // Single-page spawn of the web viewer.
        'timeout' => 20,
        // The worker rasterizes all pages in one pdftoppm spawn, so it gets a longer bound than one viewer page.
        'pages_timeout' => 60,
        'info_timeout' => 5,
        // ulimit -v (virtual) of pdfinfo/pdftoppm. Below the container limits: app 192m, ai-worker 768m (MEM_AI in compose.server.yaml, which also holds the 192m /ai-work tmpfs).
        'ulimit_kb' => 131072,
        'ulimit_kb_worker' => 262144,
    ],

    // [ASSUMED] tune after the first real run (research A8). HEIC photos are decoded to a JPEG inside the job work dir only.
    'heic' => [
        'binary' => env('AI_HEIF_BINARY', 'heif-dec'),
        'timeout' => 30,
        // ulimit -v (virtual) of the decoder; libde265 threads need more address space than poppler.
        'ulimit_kb' => 524288,
        // Cap of the decoded intermediate JPEG that is handed to ImageProcessor.
        'max_jpeg_bytes' => 33554432,
    ],

    // [ASSUMED] case-insensitive substrings of an auth failure; frozen in 08-22 from a real transcript.
    'auth_failure_patterns' => ['401', 'unauthorized', 'sign in', 'log in again', 'login again', 'refresh token'],

    // Variables that would switch the CLI to per-token billing; the worker asserts they are unset.
    'forbidden_env' => [
        'OPENAI_API_KEY', 'CODEX_API_KEY', 'ANTHROPIC_API_KEY', 'OPENAI_BASE_URL', 'OPENAI_ORG_ID',
        'CODEX_ACCESS_TOKEN', 'CODEX_AGENT_IDENTITY',
        'GH_TOKEN', 'GITHUB_TOKEN', 'COPILOT_GITHUB_TOKEN', 'COPILOT_ALLOW_ALL', 'COPILOT_PROVIDER_BASE_URL',
        'COPILOT_PROVIDER_API_KEY', 'COPILOT_PROVIDER_API_KEY_COMMAND', 'COPILOT_PROVIDER_BEARER_TOKEN',
        'COPILOT_OFFLINE', 'GH_ENTERPRISE_TOKEN', 'GITHUB_ENTERPRISE_TOKEN',
    ],

    // Name patterns of foreign secrets the ai-worker must never receive (ai:preflight reports forbidden_secret).
    // APP_KEY is covered separately through config('app.key').
    'forbidden_env_patterns' => [
        '/^(MAIL|GOOGLE|VAPID|AWS|STRIPE)_/', '/_API_KEY$/', '/_SECRET$/', '/^APP_PREVIOUS_KEYS$/',
    ],

    // Records one proposal may create when its entries are accepted (a proposal holds at most 100 entries).
    'max_accepts_per_proposal' => 100,

    // [ASSUMED] Quota guard of a start request: the subscription is flat, so queue depth and re-runs are bounded.
    // A refused start answers 409 reason 'busy' (the UI shows its generic "could not start" message).
    'limits' => [
        'max_active_per_user' => 3,
        'max_active_global' => 20,
        'document_cooldown_minutes' => 10,
    ],

    // [PROVISIONAL] owner confirms in 09-20. Needles are lowercase with a straight apostrophe; every
    // signature is ASSUMED until real transcripts are captured live (L4, L6).
    'quota' => [
        'signatures' => [
            'codex' => [
                ['needle' => 'hit your usage limit', 'minutes' => 60],
                ['needle' => 'limits reset every 5h', 'minutes' => 60],
            ],
            'copilot' => [
                ['needle' => "you've hit your session rate limit", 'minutes' => 60],
                ['needle' => "you've reached your weekly rate limit", 'minutes' => 1440],
                ['needle' => "you've hit your rate limit", 'minutes' => 60],
                ['needle' => "you've hit the rate limit for this model", 'minutes' => 10],
                ['needle' => 'plan: 100% used', 'minutes' => 1440],
            ],
        ],
        'ladder' => [60, 180, 720, 1440],
        'ladder_reset_hours' => 48,
        // A printed reset further out than this is treated as garbage (the ladder applies instead).
        'max_reset_days' => 8,
        // A cooldown from a printed reset is never longer than this: one stderr line must not park an agent for days.
        'max_cooldown_hours' => 24,
        'reset_buffer_seconds' => 60,
        'wait_cap_hours' => 24,
    ],

    // [PROVISIONAL] bulk re-reading; owner confirms in 09-20.
    'bulk' => [
        'default_cap' => 20,
        'max_cap' => 30,
        'start_hour' => 1,
        'spacing_minutes' => 10,
        'max_global_active' => 10,
        'queue' => 'ai-bulk',
        // A bulk row still queued after this long never got its job (the tick died after commit): it is failed through
        // failQueued so the run goes on, instead of waiting for the 6 h stale-queue reaper.
        'dispatch_grace_minutes' => 60,
        'fallback_agents' => false,
        'dry_run_ttl_minutes' => 15,
        'history' => 10,
        'stopped_after_hours' => 24,
    ],

    // Recognised full text of medical documents (Phase 13). Off by default. The numbers below are the decisions of
    // .planning/phases/13-ai-full-text-ocr-and-document-search-issue-11/13-EVALUATION.md: no pre-processing is applied to
    // any page image, PDF pages keep ai.pdf.scale, and the process and job timeouts above stay as they are.
    'ocr' => [
        'enabled' => (bool) env('AI_OCR_ENABLED', false),
        // reliable or uncertain; anything else reads as uncertain (config 'may contain errors' label).
        'text_reliability' => env('AI_OCR_TEXT_RELIABILITY', 'reliable'),
        'text_version' => 1,
        // max_pages * max_page_chars plus the page separators must not exceed max_total_chars (and the 200000 CHECK of the body column), so every
        // page that is read also fits the stored body; a test pins it.
        'max_page_chars' => 9900,
        'max_total_chars' => 200000,
        // Pages per transcription call, pages read at most, and the measured seconds one page takes.
        'pages_per_call' => 4,
        'max_pages' => 20,
        'seconds_per_page' => 14.52,
        // Seconds of the job budget kept free for the entry extraction after the transcription (660 - 60 - 120 - 60).
        'extract_reserve_seconds' => 120,
        // Bytes of one transcription reply file.
        'result_cap' => 524288,
        // Bytes of the whole Copilot prompt of the extraction from text (argv limit); the text is cut on a line boundary.
        'max_prompt_bytes' => 90000,
    ],

    'login_stale_minutes' => 15,
    'call_retention_months' => 12,
    'queue' => 'ai',
    // Login checks: listed before 'ai' in the worker's --queue option, so they never wait behind a backlog of extractions.
    'control_queue' => 'ai-control',
];
