<?php

// Bill routes reuse the recurring area file's params ({recurring}, {date}) and hidden item; only the payloads live here.
return [
    'payloads' => [
        'finance.bills.match' => fn (array $ctx): array => ['transaction_id' => $ctx['tx']['expense']],
        'finance.bills.record' => fn (array $ctx): array => ['amount' => '1,00', 'booked_on' => '2026-01-15', 'description' => 'x'],
    ],
];
