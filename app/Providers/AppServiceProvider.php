<?php

namespace App\Providers;

use App\Listeners\RecordPcPresenceOnLogin;
use App\Listeners\RecordPcPresenceOnLogout;
use App\Models\PcClient;
use App\Observers\AuditableObserver;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // PC presence. Registered on Laravel's own auth events rather than
        // Filament's, because Filament signs users in through the standard
        // session guard — so this works for any future auth entry point too.
        $this->app['events']->listen(Login::class, RecordPcPresenceOnLogin::class);
        $this->app['events']->listen(Logout::class, RecordPcPresenceOnLogout::class);

        $this->registerAuditObserver();
        $this->registerPanelScripts();
        $this->registerPanelStyles();
        $this->registerPcClientGuard();
    }

    /**
     * The panel JavaScript, registered once for both panels.
     *
     * Registered here rather than with `->renderHook()` on each panel provider,
     * because Panel::registerRenderHooks() pushes hooks into FilamentView's *global*
     * registry keyed only by name and scope. Both panels registering an unscoped
     * SCRIPTS_AFTER hook therefore means every panel page runs both closures: the
     * student panel was shipping the admin-only Live Screens module, and echo.js was
     * being emitted twice.
     *
     * Filament has no panel-scoped variant here. Its scoped hooks only fire when the
     * Livewire component reports matching scopes, and BasePage::getRenderHookScopes()
     * returns none — which is why every panel provider in this app registers
     * unscoped. So the scoping is done inside the closure instead, against the panel
     * actually being rendered.
     */
    private function registerPanelScripts(): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::SCRIPTS_AFTER,
            function (): string {
                $scripts = view('filament.echo-listeners')->render();

                // Live Screens is staff-only, so the student panel must not carry a
                // viewer for it. The module also no-ops when it finds no grid, but
                // shipping staff tooling to the student panel is not the place to rely
                // on that.
                if (filament()->getCurrentOrDefaultPanel()->getId() === 'admin') {
                    $scripts .= view('filament.monitoring-listeners')->render();
                }

                return $scripts;
            },
        );
    }

    /**
     * The panel stylesheet, registered once for both panels.
     *
     * The panels were shipping no application stylesheet at all. `resources/css/app.css`
     * is referenced only by welcome.blade.php, so every Tailwind utility class on a
     * custom panel page resolved to nothing: Live Screens rendered as an unstyled bulleted
     * list beside a fully styled sidebar. Nothing threw and nothing looked wrong in the
     * markup - the classes were simply never sent to the browser.
     *
     * Added with a STYLES_AFTER hook rather than the panel's `viteTheme()`. viteTheme
     * *replaces* the panel's stylesheet registration instead of adding to it, so using it
     * here removed Filament's own CSS along with the gap: the sidebar, the search field
     * and every Filament component went unstyled at once. This way Filament's stylesheet
     * is emitted by its own layout and ours is appended after it.
     *
     * A view rather than a returned string. `@vite` is a Blade directive, so returning the
     * literal text emits it onto the page as characters rather than compiling it - which
     * is why the neighbouring script hook renders views for the same reason.
     */
    private function registerPanelStyles(): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::STYLES_AFTER,
            fn (): string => view('filament.panel-styles')->render(),
        );
    }

    /**
     * A `pc_client` guard for the Lab Client, the background service on each lab PC.
     *
     * Hand-rolled rather than pulled from Sanctum: a lab PC needs one narrow
     * credential that can only post its own screen, whereas a Sanctum personal access
     * token carries a user's whole authority. Keeping it here also means no new
     * dependency, which AGENTS.md requires approval for.
     *
     * The token arrives as a bearer header and is matched by its SHA-256 digest, so
     * nothing sensitive is compared against a stored plaintext and the lookup cannot
     * be timed character by character. `findByToken` also refuses anything not in the
     * active state, which is what makes revocation immediate rather than eventual.
     */
    private function registerPcClientGuard(): void
    {
        Auth::viaRequest('pc_client', fn (Request $request): ?PcClient => PcClient::findByToken(
            $request->bearerToken(),
        ));
    }

    /**
     * Attach the audit observer to every model named in `config('lab.audit.models')`.
     *
     * Config-driven so adding a model to the trail is a config change rather than
     * an edit here, which is the whole point of the explicit list: attendance and
     * chat volumes would otherwise bury the entries that matter.
     */
    private function registerAuditObserver(): void
    {
        foreach ((array) config('lab.audit.models', []) as $model) {
            if (! class_exists($model)) {
                continue;
            }

            $model::observe(AuditableObserver::class);
        }
    }
}
