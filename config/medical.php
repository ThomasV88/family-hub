<?php

return [
    'document_max_bytes' => 10485760,
    'image_full_px' => 2400,
    'image_thumb_px' => 480,
    'max_documents_per_subject' => 200,
    'upload_batch_max' => 10,
    'max_contacts' => 3,
    // Visits, medications, allergies and vaccinations together, enforced on the AI accept path under the person lock.
    'max_records_per_subject' => 1000,
    'vaccination_lead_days' => [14, 0],
    'reminder_hour' => 8,
    'due_soon_days' => 30,
    'orphan_grace_minutes' => 60,
    // Circuit breaker of medical:prune-orphans: more than this many candidates AND more than this share of the inventory aborts.
    'orphan_max_delete' => 20,
    'orphan_max_ratio' => 0.25,
    'export_large_visit_threshold' => 500,
];
