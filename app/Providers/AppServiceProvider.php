<?php

namespace App\Providers;

use App\Listeners\RecordPcPresenceOnLogin;
use App\Listeners\RecordPcPresenceOnLogout;
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
    }
}
