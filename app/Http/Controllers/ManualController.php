<?php

namespace App\Http\Controllers;

use App\Manual\ManualRepository;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The user manual. Open to every signed-in member (no module grant); it holds no personal data, only help text. */
class ManualController extends Controller
{
    public function __invoke(Request $request, ManualRepository $manual): Response
    {
        $content = $manual->for($request->user(), app()->getLocale());

        return Inertia::render('Manual/Index', [
            'categories' => $content['categories'],
            'topics' => $content['topics'],
        ]);
    }
}
