<?php

return [
    'currencies' => ['EUR', 'PLN', 'CZK', 'USD', 'GBP'],
    'default_currency' => 'EUR',
    'max_urls' => 5,
    'title_max' => 120,
    'notes_max' => 2000,
    'size_notes_max' => 200,
    'upload_max_kb' => 5120,
    'fetch_html_max_bytes' => 524288,
    'fetch_image_max_bytes' => 5242880,
    // Lowered from 16 MP for the 128M production memory_limit (see plan 04-07 memory budget).
    'image_max_pixels' => 13000000,
    'image_full_px' => 1280,
    'image_thumb_px' => 480,
    'christmas' => ['month' => 12, 'day' => 24],
    'reminder_hour' => 8,
    'allowed_lead_days' => [14, 7, 3, 1],
    'default_lead_days' => [14, 3],
    'reservation_retention_days' => 30,
    'strip_days' => 60,
];
