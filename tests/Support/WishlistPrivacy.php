<?php

namespace Tests\Support;

use App\Access\Preset;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Models\Wish;
use App\Models\WishContribution;
use App\Models\WishlistManager;
use App\Models\WishReservation;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shared machinery of the reservation leak tests: surfaces are enumerated from the router at run time,
 * so routes added by later plans are covered the moment they exist.
 */
class WishlistPrivacy
{
    public const CANARY_NAME = 'ZxCanaryGiver';

    public const CANARY_AMOUNT_MINOR = 777700;

    /** @return list<LaravelRoute> every GET route of the wishlist plus the pages that could summarise it */
    public static function surfaces(): array
    {
        $extra = ['home', 'settings.index', 'settings.notifications'];
        $out = [];
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if ($name === null || ! in_array('GET', $route->methods(), true)) {
                continue;
            }
            if (str_starts_with($name, 'wishlist.') || in_array($name, $extra, true)) {
                $out[] = $route;
            }
        }

        return $out;
    }

    /** @return list<LaravelRoute> every non-GET wishlist.giving.* route */
    public static function givingWrites(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if ($name !== null && str_starts_with($name, 'wishlist.giving.') && ! in_array('GET', $route->methods(), true)) {
                $out[] = $route;
            }
        }

        return $out;
    }

    /**
     * owner and manager are recipient-side; giver and giver2 are not.
     *
     * @return array{owner: User, manager: User, giver: User, giver2: User, wish: Wish, twin: Wish}
     */
    public static function scenario(): array
    {
        $owner = User::factory()->withPreset(Preset::Parent)->create(['name' => 'Olga Owner']);
        $manager = User::factory()->withPreset(Preset::Parent)->create(['name' => 'Milan Manager']);
        WishlistManager::factory()->create(['owner_id' => $owner->id, 'manager_id' => $manager->id]);
        $giver = User::factory()->withPreset(Preset::Child)->create(['name' => self::CANARY_NAME]);
        $giver2 = User::factory()->withPreset(Preset::Grandparent)->create(['name' => 'Gita Giver']);

        return [
            'owner' => $owner,
            'manager' => $manager,
            'giver' => $giver,
            'giver2' => $giver2,
            'wish' => Wish::factory()->create(['owner_id' => $owner->id, 'title' => 'Plain wish']),
            'twin' => Wish::factory()->create(['owner_id' => $owner->id, 'title' => 'Twin wish']),
        ];
    }

    /** A reservation by the canary giver, co-buy open, plus a second giver's 7777.00 share. */
    public static function seedReservationWithCoBuy(Wish $wish, User $giver, User $giver2): WishReservation
    {
        $reservation = WishReservation::factory()->cobuyOpen()->create(['wish_id' => $wish->id, 'user_id' => $giver->id]);
        WishContribution::factory()->create([
            'reservation_id' => $reservation->id,
            'user_id' => $giver2->id,
            'amount_minor' => self::CANARY_AMOUNT_MINOR,
        ]);

        return $reservation;
    }

    /**
     * @param  array<string, mixed>  $ctx  scenario
     * @return array<string, string|int>
     */
    public static function params(LaravelRoute $route, array $ctx): array
    {
        $providers = [
            'wish' => fn () => $ctx['wish']->id,
            'variant' => fn () => 'thumb',
            'member' => fn () => $ctx['owner']->id,
            'occasion' => fn () => self::occasionId($ctx['owner']),
        ];
        $params = [];
        foreach ($route->parameterNames() as $name) {
            if (! isset($providers[$name])) {
                throw new RuntimeException('No privacy-test provider for parameter {'.$name.'} of route '.$route->getName());
            }
            $params[$name] = $providers[$name]();
        }

        return $params;
    }

    private static function occasionId(User $owner): int
    {
        $model = 'App\\Models\\WishOccasion';
        if (! class_exists($model)) {
            throw new RuntimeException('Route with {occasion} exists but '.$model.' does not');
        }

        return $model::factory()->create(['user_id' => $owner->id])->id;
    }

    /**
     * URLs requested for one route: the route itself and, for the list page, its query variants.
     *
     * @param  array<string, mixed>  $ctx
     * @return list<string>
     */
    public static function urls(LaravelRoute $route, array $ctx): array
    {
        $url = route($route->getName(), self::params($route, $ctx), false);
        if ($route->getName() !== 'wishlist.index') {
            return [$url];
        }
        $member = $ctx['owner']->id;

        return [
            $url,
            $url.'?member='.$member,
            $url.'?member='.$member.'&wish='.$ctx['wish']->id,
            $url.'?member='.$member.'&available=1',
        ];
    }

    /**
     * Requests every surface twice (Inertia and HTML) as $viewer and returns the normalised responses.
     *
     * @param  array<string, mixed>  $ctx
     * @return array<string, string>
     */
    public static function capture(object $test, User $viewer, array $ctx): array
    {
        $version = self::inertiaVersion();
        $out = [];
        foreach (self::surfaces() as $route) {
            foreach (self::urls($route, $ctx) as $url) {
                foreach (['inertia', 'html'] as $kind) {
                    app('auth')->forgetGuards();
                    app('cache')->store()->flush();
                    $test->flushSession();
                    $headers = $kind === 'inertia' ? ['X-Inertia' => 'true', 'X-Inertia-Version' => $version] : [];
                    $response = $test->actingAs($viewer)->get($url, $headers);
                    $out[$kind.' '.$url] = self::normalise($response);
                }
            }
        }

        return $out;
    }

    public static function inertiaVersion(): string
    {
        return (string) (app(HandleInertiaRequests::class)->version(Request::create('/')) ?? '');
    }

    /** Status, redirect target and body with only the per-session noise removed (CSRF token). */
    public static function normalise(TestResponse $response): string
    {
        $body = $response->baseResponse instanceof BinaryFileResponse
            || $response->baseResponse instanceof StreamedResponse
            ? 'binary:'.md5((string) $response->streamedContent())
            : (string) $response->getContent();

        $body = preg_replace('/(name="csrf-token"\s+content=")[^"]*/', '$1', $body) ?? $body;
        $body = preg_replace('/"csrf_token"\s*:\s*"[^"]*"/', '"csrf_token":""', $body) ?? $body;

        return $response->getStatusCode()."\n".(string) $response->headers->get('Location')."\n".$body;
    }

    /** The text a canary scan runs over: ISO timestamps removed so microsecond digits cannot collide. */
    public static function scannable(string $normalised): string
    {
        return preg_replace('/\d{4}-\d{2}-\d{2}T[\d:.]+Z?/', '', $normalised) ?? $normalised;
    }
}
