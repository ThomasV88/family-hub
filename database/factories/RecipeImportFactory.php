<?php

namespace Database\Factories;

use App\Models\RecipeImport;
use App\Models\User;
use App\Recipes\Import\ImportUrl;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Rows only; default state is a fetching import. State methods keep the table CHECKs satisfied.
 *
 * @extends Factory<RecipeImport>
 */
class RecipeImportFactory extends Factory
{
    protected $model = RecipeImport::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $url = 'https://recipes.example/r/'.fake()->unique()->numberBetween(1, 1000000);

        return [
            'user_id' => User::factory(),
            'url' => $url,
            'url_hash' => ImportUrl::hash($url),
            'host' => 'recipes.example',
            'status' => 'fetching',
        ];
    }

    public function forUrl(string $url): static
    {
        return $this->state(fn () => ['url' => $url, 'url_hash' => ImportUrl::hash($url), 'host' => (string) parse_url($url, PHP_URL_HOST)]);
    }

    public function queued(string $text = 'Pancakes. Flour, milk, eggs. Mix and fry.', string $kind = 'text'): static
    {
        return $this->state(fn () => ['status' => 'queued', 'source_text' => $text, 'source_kind' => $kind, 'queued_at' => now()]);
    }

    public function running(string $text = 'Pancakes. Flour, milk, eggs. Mix and fry.', string $kind = 'text'): static
    {
        return $this->state(fn () => ['status' => 'running', 'source_text' => $text, 'source_kind' => $kind, 'queued_at' => now(), 'started_at' => now()]);
    }

    /** @param  array<string, mixed>  $payload */
    public function ready(array $payload = ['is_recipe' => true]): static
    {
        return $this->state(fn () => ['status' => 'ready', 'payload' => $payload, 'agent' => 'codex', 'model' => 'default', 'queued_at' => now(), 'started_at' => now(), 'finished_at' => now()]);
    }

    public function failed(string $code = 'cli_error'): static
    {
        return $this->state(fn () => ['status' => 'failed', 'failure_code' => $code, 'finished_at' => now()]);
    }

    public function cancelled(string $reason = 'user'): static
    {
        return $this->state(fn () => ['status' => 'cancelled', 'cancel_reason' => $reason, 'finished_at' => now()]);
    }
}
