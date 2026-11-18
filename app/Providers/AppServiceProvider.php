<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Protegey\Sdk\Protegey;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One SDK instance per request, built from config/services.php. Sandbox vs live is decided
        // purely by which API key PROTEGEY_API_KEY holds. Tests swap this binding for a mocked one.
        $this->app->singleton(Protegey::class, fn () => new Protegey(
            (string) config('services.protegey.api_key'),
            (string) config('services.protegey.base_url'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
