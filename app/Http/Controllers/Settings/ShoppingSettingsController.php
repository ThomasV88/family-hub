<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Shopping\Queries\SettingsPayload;
use App\Support\SettingsSections;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Settings > Shopping: the shared item catalog, stores and units. The route middleware requires Shopping edit. */
class ShoppingSettingsController extends Controller
{
    public function __construct(private readonly SettingsPayload $payload) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Settings/Shopping', ['sections' => SettingsSections::for($user)] + $this->payload->for($user));
    }
}
