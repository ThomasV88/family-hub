<?php

return [
    // Hours after creation during which a health record or person may still be deleted for good. Older ones can only be
    // archived (hidden, restorable by a member with Edit). 0 = always archive. Clamped to 0..8760 (one year).
    'delete_window_hours' => max(0, min(8760, (int) env('MEDICAL_DELETE_WINDOW_HOURS', 72))),
    'document_max_bytes' => 10485760,
    // Stored image: longest edge and JPEG quality (owner decision WR-12: 3600 px, q90; was 2400 px, q82). The re-encode strips
    // EXIF and GPS and the original is not kept. The input pixel cap stays wishlist.image_max_pixels (13 MP). Because the
    // resized copy is now as large as 10 MP, the decode is also bounded by an estimate of its memory (input bytes plus 4.4
    // bytes per pixel of source and of output) that must stay under image_decode_budget_bytes, which keeps the whole decode
    // inside the app container's 128M PHP memory_limit (a 12 MP phone photo of up to about 4 MB passes). See docs/ops/server.md.
    'image_full_px' => 3600,
    'image_thumb_px' => 480,
    'image_jpeg_quality' => 90,
    'image_decode_budget_bytes' => 106000000,
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
