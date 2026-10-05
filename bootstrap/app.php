<?php

use Filament\Facades\Filament;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Filament registers its login routes per panel (`filament.admin.login`,
        // `filament.student.login`) and never a bare `login`. Left alone, the auth
        // middleware's default redirect throws "Route [login] not defined" and a
        // guest gets a 500 instead of being sent to sign in.
        //
        // Resolved through the panel rather than route(), because Filament only
        // registers a panel's routes when the request is for that panel — a plain
        // web route has none registered, so the name lookup would fail too.
        $middleware->redirectGuestsTo(
            fn (): string => Filament::getPanel('admin')->getLoginUrl(),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
