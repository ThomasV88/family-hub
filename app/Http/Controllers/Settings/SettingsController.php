<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\SettingsSections;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Settings/Index', ['sections' => SettingsSections::for($request->user())]);
    }

    public function appearance(Request $request): Response
    {
        return Inertia::render('Settings/Appearance', ['sections' => SettingsSections::for($request->user())]);
    }
}
