<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class StyleGuideController extends Controller
{
    public function __invoke(): Response
    {
        abort_unless(app()->environment(config('app.style_guide_environments', [])), 404);

        return Inertia::render('StyleGuide');
    }
}
