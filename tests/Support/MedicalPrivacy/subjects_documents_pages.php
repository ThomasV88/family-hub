<?php

// Page-image route of the documents area (sorts after subjects_documents.php, which seeds the PDFs). The seeded PDFs are
// not renderable stubs, so the route answers the same 403 for visible and hidden subjects; DocumentPageImageTest is the
// content proof.
return [
    'params' => [
        'page' => fn (array $ctx): string => '1',
    ],
];
