<?php

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

/**
|--------------------------------------------------------------------------
| Lab Client route middleware
|--------------------------------------------------------------------------
|
| Exists because of a bug the rest of the suite could not see.
|
| The client endpoints were declared in routes/web.php, so they inherited the `web`
| group's PreventRequestForgery. A lab PC has no session and therefore no CSRF token
| to present, so every frame was refused with HTTP 419 before the controller ran —
| the client logged "frame rejected" once a second, forever.
|
| All the feature tests passed anyway, because Laravel's CSRF middleware
| short-circuits inside runningUnitTests(). Nothing about a request succeeding can
| catch this; only looking at the middleware can.
|
| So these tests assert the middleware list directly. They are the only tests here
| that would notice a client endpoint being moved back into `web`.
 */
describe('Lab Client route middleware', function (): void {
    /**
     * The resolved middleware for a route, with the `web` and `api` group names
     * expanded into the concrete classes they carry.
     *
     * gatherRouteMiddleware() is needed rather than gatherMiddleware(), which returns
     * the group name unexpanded — and the group is the whole question here.
     *
     * @return array<int, string>
     */
    function resolvedMiddleware(string $routeName): array
    {
        return app('router')->gatherRouteMiddleware(
            Route::getRoutes()->getByName($routeName),
        );
    }

    it('does not put CSRF verification in front of the client endpoints', function (): void {
        foreach (['client.frames.store', 'client.heartbeat', 'client.config'] as $name) {
            expect(resolvedMiddleware($name))
                ->not->toContain(PreventRequestForgery::class)
                ->not->toContain(StartSession::class);
        }
    });

    it('puts the client endpoints in the api group, not the web group', function (): void {
        foreach (['client.frames.store', 'client.heartbeat', 'client.config'] as $name) {
            // The api group is marker-only; the web group brings session cookies and
            // CSRF with it. Its absence is what allows a stateless machine to post.
            expect(Route::getRoutes()->getByName($name)->getAction('middleware'))
                ->toContain('api')
                ->not->toContain('web');
        }
    });

    it('keeps session middleware off the frame endpoint so a machine needs no cookies', function (): void {
        expect(resolvedMiddleware('client.frames.store'))
            ->not->toContain(StartSession::class)
            ->not->toContain(AddQueuedCookiesToResponse::class);
    });

    it('still requires the machine credential on every client endpoint', function (): void {
        foreach (['client.frames.store', 'client.heartbeat', 'client.config'] as $name) {
            expect(Route::getRoutes()->getByName($name)->gatherMiddleware())
                ->toContain('auth:pc_client');
        }
    });

    it('leaves the staff frame route in the web group, where it belongs', function (): void {
        // The teacher-facing route reads the PC from the session, so it wants exactly
        // the opposite treatment to the machine-facing ones: session, cookies, CSRF.
        expect(resolvedMiddleware('monitoring.frames.show'))
            ->toContain(PreventRequestForgery::class)
            ->toContain(StartSession::class)
            ->toContain(SubstituteBindings::class);
    });

    it('does not verify CSRF on the client GET, which is safe without a token', function (): void {
        // Recorded for completeness: Laravel only verifies unsafe methods, so
        // GET /api/client/config never needed an exemption. If the config endpoint
        // ever gains a mutating sibling, this is the assertion that would need to move.
        expect(Route::getRoutes()->getByName('client.config')->methods())
            ->toContain('GET');
    });
});
