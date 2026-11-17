<?php

namespace Tests\Support;

use App\Medical\Ai\AgentHealth;
use App\Medical\Ai\LoginState;
use App\Models\AiAgentSetting;
use App\Models\User;
use App\Wishlist\Fetch\SafeFetcher;
use GuzzleHttp\Handler\MockHandler;
use Illuminate\Support\Facades\DB;

/** Shared builders for the recipe import tests: agents, members, a fake-network fetcher and fake CLI replies. */
final class RecipeImportFixtures
{
    public const PAGE_HOST = 'recipes.example';

    public const PAGE_URL = 'https://recipes.example/pancakes';

    /** Codex enabled (the default) with a usable login state. */
    public static function enableCodex(): void
    {
        AiAgentSetting::query()->toBase()->where('agent', 'codex')->update(['enabled' => true]);
        (new AgentHealth)->recordCheck('codex', LoginState::LoggedIn);
    }

    public static function enableCopilot(): void
    {
        AiAgentSetting::query()->toBase()->where('agent', 'copilot')->update(['enabled' => true]);
        (new AgentHealth)->recordCheck('copilot', LoginState::LoggedIn);
    }

    /** A member with Recipes edit; the recipe notice is acknowledged for $providers unless that is null. */
    public static function member(?string $providers = 'codex', string $level = 'edit'): User
    {
        $user = RecipeFixtures::member($level);
        if ($providers !== null) {
            DB::table('ai_notice_acks')->insert(['user_id' => $user->id, 'providers' => null, 'recipe_providers' => $providers]);
        }

        return $user;
    }

    /**
     * A MockHandler-backed SafeFetcher with a fake DNS table. The container binding honours the userAgent parameter, as
     * FetchRecipePage asks for it.
     *
     * @param  array<int, mixed>  $responses
     * @param  array<string, list<string>>  $dns
     */
    public static function fetcher(array $responses, array $dns = []): MockHandler
    {
        $mock = new MockHandler($responses);
        $dns = $dns + [self::PAGE_HOST => ['93.184.216.34']];
        app()->bind(SafeFetcher::class, fn ($app, array $params = []) => new SafeFetcher(
            new FakeDnsResolver($dns),
            null,
            $mock,
            3,
            [80, 443],
            $params['userAgent'] ?? null,
        ));

        return $mock;
    }

    /** @param  array<string, mixed>  $over */
    public static function recipeReply(array $over = []): string
    {
        return (string) json_encode(array_replace([
            'schema_version' => 1,
            'is_recipe' => true,
            'title' => 'Pancakes',
            'description' => 'Thin pancakes.',
            'servings' => 4,
            'prep_minutes' => 10,
            'cook_minutes' => 15,
            'categories' => ['breakfast'],
            'ingredients' => [
                ['name' => 'Flour', 'quantity' => 250, 'unit' => 'g', 'note' => null, 'original' => '250 g flour'],
                ['name' => 'Milk', 'quantity' => 500, 'unit' => 'ml', 'note' => null, 'original' => '500 ml milk'],
            ],
            'steps' => ['Mix the batter.', 'Fry thin pancakes.'],
        ], $over));
    }

    public static function jsonLdPage(): string
    {
        $ld = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'Recipe',
            'name' => 'Pancakes',
            'recipeIngredient' => ['250 g flour', '500 ml milk', '2 eggs'],
            'recipeInstructions' => [['@type' => 'HowToStep', 'text' => 'Mix the batter.'], ['@type' => 'HowToStep', 'text' => 'Fry thin pancakes.']],
        ]);

        return '<html><head><title>Pancakes</title><script type="application/ld+json">'.$ld.'</script></head><body><p>Pancakes</p></body></html>';
    }
}
