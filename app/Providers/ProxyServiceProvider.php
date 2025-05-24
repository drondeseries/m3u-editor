<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\ProxyService;

class ProxyServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('proxy', function ($app) {
            return new ProxyService();
        });
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
