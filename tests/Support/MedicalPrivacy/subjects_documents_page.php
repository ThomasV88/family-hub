<?php

// Person-page panels of the documents area (sorts after subjects_documents.php, which seeds `document`). The visible
// subject's document opens its panel, the upload panel opens, and the documents timeline filter resolves; the
// hidden-subject differential then covers the same queries against the hidden people.
return [
    'panels' => [
        'health.people.show' => fn (array $ctx): array => [
            ['panel' => 'document', 'id' => $ctx['document']],
            ['panel' => 'new-document'],
            ['view' => 'timeline', 'filter' => 'documents'],
        ],
    ],
];
