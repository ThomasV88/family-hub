<?php

return [
    'import' => [
        'max_bytes' => 2097152,
        'max_rows' => 5000,
        'draft_ttl_hours' => 24,
        'probable_window_days' => 3,
        'description_max' => 500,
        'counterparty_max' => 200,
        'reference_max' => 100,
        'encodings' => ['UTF-8', 'UTF-16LE', 'UTF-16BE', 'WINDOWS-1250', 'ISO-8859-2', 'CP852'],
        'delimiters' => [';', ',', "\t", '|'],
        'date_formats' => ['d.m.Y', 'j.n.Y', 'Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.y', 'm/d/Y'],
    ],
    'recurring' => [
        'overdue_lookback_days' => 45,
        'manual_candidate_days' => 60,
        'default_window_days' => 5,
        'window_days_options' => [1, 2, 3, 5, 7, 10, 14],
    ],
    'transactions' => [
        'page_size' => 50,
    ],
];
