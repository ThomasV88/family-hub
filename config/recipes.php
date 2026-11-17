<?php

/** Recipe book limits; every later Recipes plan reads these keys. */
return [
    'title_max' => 120,
    'description_max' => 1000,
    'ingredient_name_max' => 120,
    'ingredient_note_max' => 80,
    'step_max' => 1000,
    'ingredients_max' => 80,
    'steps_max' => 60,
    'categories_max' => 3,
    'category_max' => 30,
    'servings_min' => 1,
    'servings_max' => 99,
    'servings_default' => 4,
    'minutes_max' => 1440,
    'quantity_max' => 9999,
    'member_note_max' => 2000,
    'source_url_max' => 2000,
    'per_page' => 24,

    // Labels come from lang recipes.category_starter.*.
    'starter_categories' => ['breakfast', 'lunch', 'dinner', 'soup', 'salad', 'dessert', 'baking', 'snack', 'drink', 'vegetarian'],

    'photos' => [
        'max_count' => 10,
        'max_bytes' => 5242880,
        'full_px' => 1280,
        'thumb_px' => 480,
    ],

    'shopping' => [
        'items_max' => 80,
        'list_name_max' => 80,
    ],

    // Values are assumptions; tune after staging.
    'import' => [
        'html_max_bytes' => 2097152,
        'decoded_max_bytes' => 4194304,
        'max_text_chars' => 24000,
        'max_prompt_bytes' => 90000,
        'active_per_user' => 2,
        'per_user_per_day' => 20,
        'global_active' => 10,
        'ready_ttl_hours' => 24,
        'retention_days' => 7,
        'user_agent' => 'FamilyHubRecipeFetcher/1.0',
        // Minutes after a fetch STARTED; and after creation for a fetch whose job never started (a backlog on the shared queue).
        'reap_fetching_minutes' => 5,
        'reap_fetch_unstarted_minutes' => 60,
        'reap_queued_minutes' => 30,
        'reap_running_minutes' => 15,
    ],
];
