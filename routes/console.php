<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Lab housekeeping
|--------------------------------------------------------------------------
|
| The legacy app had no scheduler at all. Idle machines were collected by
| `GET /api/pcs/cleanup-stale`, which the PC view called on every page load —
| so a lab could sit uncollected indefinitely unless somebody happened to open
| that screen. Scheduling it makes collection independent of who is looking.
|
*/

Schedule::command('pcs:mark-stale-offline')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();
