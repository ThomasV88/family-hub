<?php

namespace App\Http\Controllers\Settings;

use App\Help\HelpRegistry;
use App\Help\HelpTexts;
use App\Http\Controllers\Controller;
use App\Support\SettingsSections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin management of the contextual help (the green "?" tips): the list of every tip with its placement and its texts in all
 * languages, and the edit endpoint behind both this list and Ctrl+click on a tip. Texts are plain text of at most 400
 * characters. Editing grants nothing and exposes no data, so it needs the admin role but no recent re-confirmation (PROVISIONAL).
 */
final class HelpTextsController extends Controller
{
    public function index(Request $request, HelpTexts $help): Response
    {
        $registry = $help->registry();
        $locale = app()->getLocale();
        $all = $help->all($locale);

        return Inertia::render('Settings/Help', [
            'sections' => SettingsSections::for($request->user()),
            'topics' => $all['topics'],
            'orphans' => $all['orphans'],
            'areas' => collect($registry->areas($locale))->map(fn (string $label, string $id) => ['id' => $id, 'label' => $label])->values()->all(),
            'locales' => HelpRegistry::LOCALES,
            'maxLength' => HelpRegistry::MAX_LENGTH,
        ]);
    }

    public function show(HelpTexts $help, string $key): JsonResponse
    {
        $detail = $help->find($key, app()->getLocale());
        abort_if($detail === null, 404);

        return $this->json(['topic' => $detail]);
    }

    public function update(Request $request, HelpTexts $help, string $key): JsonResponse
    {
        abort_unless($help->registry()->has($key), 404);

        $valid = $request->validate([
            'texts' => ['sometimes', 'array:'.implode(',', HelpRegistry::LOCALES)],
            'texts.*' => ['string', 'max:'.HelpRegistry::MAX_LENGTH, 'not_regex:/[<>]/', 'not_regex:/[\x00-\x08\x0B-\x1F\x7F]/'],
            'reset' => ['sometimes', 'array', 'max:3'],
            'reset.*' => ['string', 'in:'.implode(',', HelpRegistry::LOCALES), 'distinct'],
        ]);
        $texts = $valid['texts'] ?? [];
        $reset = array_values($valid['reset'] ?? []);

        if ($texts === [] && $reset === []) {
            throw ValidationException::withMessages(['texts' => 'Nothing to save.']);
        }
        if (array_intersect(array_keys($texts), $reset) !== []) {
            throw ValidationException::withMessages(['reset' => 'A language cannot be both edited and reset.']);
        }

        $help->update($request->user(), $key, $texts, $reset);
        $locale = app()->getLocale();

        return $this->json([
            'topic' => $help->find($key, $locale),
            'current' => ['title' => $help->registry()->title($key, $locale), 'text' => $help->payload($locale)['topics'][$key]['text']],
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)->header('Cache-Control', 'private, no-store');
    }
}
