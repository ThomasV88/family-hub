<?php

namespace App\Http\Controllers;

use App\Jobs\RecordHeartbeat;
use Illuminate\Http\RedirectResponse;

class HeartbeatController extends Controller
{
    public function store(): RedirectResponse
    {
        RecordHeartbeat::dispatch('manual');

        return redirect()->route('home', status: 303);
    }
}
