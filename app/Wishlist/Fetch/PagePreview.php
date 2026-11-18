<?php

namespace App\Wishlist\Fetch;

/** What a first link yielded: plain-text title and description, whether a picture was found, and per-field failure codes. */
final class PagePreview
{
    /** @param  array<string, string>  $failures  field (image|title|description) => timeout|blocked|no_tags|unreachable */
    public function __construct(
        public readonly ?string $title,
        public readonly ?string $description,
        public readonly bool $hasImage,
        public readonly array $failures = [],
    ) {}

    public static function failed(string $reason): self
    {
        return new self(null, null, false, ['image' => $reason, 'title' => $reason, 'description' => $reason]);
    }

    public static function fromHtml(string $html, string $finalUrl, bool $hasImage): self
    {
        $title = MetaImageExtractor::title($html);
        $description = MetaImageExtractor::description($html);
        $failures = [];
        foreach (['title' => $title, 'description' => $description] as $field => $value) {
            if ($value === null) {
                $failures[$field] = 'no_tags';
            }
        }
        if (! $hasImage) {
            $failures['image'] = 'no_tags';
        }

        return new self($title, $description, $hasImage, $failures);
    }
}
