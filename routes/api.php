<?php

use App\Http\Controllers\LabClientConfig;
use App\Http\Controllers\LabClientHeartbeat;
use App\Http\Controllers\StoreScreenFrame;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Lab Client endpoints
|--------------------------------------------------------------------------
|
| Machine-to-server, authenticated by a bearer token that is matched against a
| SHA-256 digest on the pc_clients row. See App\Providers\AppServiceProvider for why
| that is hand-rolled rather than pulled from Sanctum.
|
| These live here, in the api group, rather than in web.php — and that is not a
| stylistic preference.
|
| web.php's group includes PreventRequestForgery, which rejects any POST without a
| matching CSRF token with HTTP 419. A lab PC has no session and therefore no token to
| present, so every frame was refused before the controller ran. Worse, this was
| invisible to the test suite: Laravel's CSRF middleware short-circuits in
| runningUnitTests(), so all 402 tests passed against endpoints that could never work
| against a real machine. LabClientRouteMiddlewareTest now asserts the middleware
| rather than relying on the request succeeding.
|
| No rate limiter here. Every request already requires a 240-bit token matched by
| index lookup, so the cost of guessing is a hash and a primary key probe. Throttling
| would protect nothing and would get in the way of 40 machines posting a frame a
| second.
|
| The frame body is raw JPEG, not multipart. GET /config needs no CSRF token because
| Laravel only verifies unsafe methods.
|
*/
Route::middleware('auth:pc_client')->prefix('client')->group(function () {
    Route::post('/frame', StoreScreenFrame::class)->name('client.frames.store');

    Route::get('/config', LabClientConfig::class)->name('client.config');

    Route::post('/heartbeat', LabClientHeartbeat::class)->name('client.heartbeat');
});
