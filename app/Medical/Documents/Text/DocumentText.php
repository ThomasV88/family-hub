<?php

namespace App\Medical\Documents\Text;

/**
 * The recognised text of one document, as a plain value object. Deliberately not an Eloquent model and never a relation
 * of MedicalDocument: the text must not ride along when a document is serialised into a page prop, an export or a log.
 */
final readonly class DocumentText
{
    public function __construct(
        public string $documentId,
        public string $subjectId,
        public int $pagesRead,
        public int $pagesTotal,
        public bool $complete,
        public ?string $language,
        public string $body,
    ) {}

    /** @return list<string> */
    public function pages(): array
    {
        return explode(TextNormalizer::PAGE_SEPARATOR, $this->body);
    }
}
