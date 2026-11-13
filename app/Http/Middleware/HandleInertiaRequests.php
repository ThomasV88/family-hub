<?php

namespace App\Http\Middleware;

use App\Access\AccessGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        // Evaluated lazily, at render time, so a controller may still switch the locale (the invite page does).
        // Only allowlisted codes reach lang_path.
        $locale = fn (): string => in_array(app()->getLocale(), SetLocale::SUPPORTED, true) ? app()->getLocale() : 'en';

        return [
            ...parent::share($request),
            'locale' => $locale,
            'availableLocales' => SetLocale::SUPPORTED,
            'messages' => fn () => json_decode(File::get(lang_path($locale().'.json')), true, flags: JSON_THROW_ON_ERROR),
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'locale' => $request->user()->locale,
                    'isAdmin' => (bool) $request->user()->is_admin,
                ] : null,
                'access' => $request->user() ? app(AccessGate::class)->levels($request->user()) : (object) [],
            ],
            'flash' => [
                'status' => $request->session()->get('status'),
                'toast' => $request->session()->get('toast'),
                'data' => $request->session()->get('flash_data'),
            ],
            'app' => [
                'name' => config('app.name'),
                'env' => app()->environment(),
                'styleGuide' => app()->environment(config('app.style_guide_environments', [])),
            ],
        ];
    }
}
