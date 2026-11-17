<?php

return [
    // Catalog item pictures: longest edge in pixels of the two stored images.
    'catalog_image_full_px' => 512,
    'catalog_image_thumb_px' => 192,
    'catalog_image_max_bytes' => 5242880,

    // Store logos.
    'store_logo_full_px' => 320,
    'store_logo_thumb_px' => 128,
    'store_logo_max_bytes' => 2097152,

    'name_max' => [
        'list' => 80,
        'catalog' => 80,
        'store' => 60,
        'unit' => 12,
        'note' => 200,
    ],

    'quantity_max' => 999.99,
    'items_per_add_request' => 50,
    'items_per_list' => 500,
    // At most this many people per role in the list settings.
    'members_per_role' => 50,

    // E-mailing a list (research A1).
    'mail' => [
        'per_send' => 5,
        'per_user_hour' => 10,
        'per_user_day' => 30,
        'per_recipient_day' => 5,
    ],

    // A reminder that is still unsent this long after its time is never fired (the scheduler was down, or push was off).
    'reminder_max_age_hours' => 24,

    'archive_per_page' => 20,
];
