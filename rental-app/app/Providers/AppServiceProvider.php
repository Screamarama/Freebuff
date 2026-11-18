<?php

namespace App\Providers;

use App\Models\Theme;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
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
        // Share the tenant's brand colors with the app chrome.
        // Guests and super admins (no tenant context) get the defaults.
        View::composer('layouts.app', function ($view) {
            $view->with('activeTheme', Theme::effectiveFor(Auth::user()?->business_id));
        });
    }
}
