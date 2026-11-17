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

    // E-mailing a list (research A1).
    'mail' => [
        'per_send' => 5,
        'per_user_hour' => 10,
        'per_user_day' => 30,
        'per_recipient_day' => 5,
    ],

    'archive_per_page' => 20,
];
