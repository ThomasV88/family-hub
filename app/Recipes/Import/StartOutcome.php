<?php

namespace App\Recipes\Import;

/**
 * Result of RecipeImportService::start. `outcome` is one of started, reused, invalid_url, unavailable, notice_required
 * or limit; `id` is set for started and reused, `providers` for notice_required.
 */
final readonly class StartOutcome
{
    /** @param  list<string>  $providers */
    public function __construct(
        public string $outcome,
        public ?string $id = null,
        public array $providers = [],
    ) {}

    public function ok(): bool
    {
        return $this->outcome === 'started' || $this->outcome === 'reused';
    }
}
