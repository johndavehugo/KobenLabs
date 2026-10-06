<?php

namespace App\Providers;

use App\Listeners\RecordPcPresenceOnLogin;
use App\Listeners\RecordPcPresenceOnLogout;
use App\Observers\AuditableObserver;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
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
