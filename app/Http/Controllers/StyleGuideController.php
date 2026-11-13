<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class StyleGuideController extends Controller
{
    public function __invoke(): Response
    {
        abort_unless(app()->environment(['local', 'staging', 'testing']), 404);

        return Inertia::render('StyleGuide');
    }
}
