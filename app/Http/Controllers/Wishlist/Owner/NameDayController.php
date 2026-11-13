<?php

namespace App\Http\Controllers\Wishlist\Owner;

use App\Http\Controllers\Controller;
use App\Wishlist\Occasions\NameDayCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NameDayController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'calendar' => ['required', 'string', 'in:sk,pl'],
            'q' => ['nullable', 'string', 'max:40'],
        ]);

        return response()->json([
            'results' => NameDayCalendar::search($data['calendar'], (string) ($data['q'] ?? ''), 8),
        ]);
    }
}
