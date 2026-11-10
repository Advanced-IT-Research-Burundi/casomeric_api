<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
        $this->app->register(ScrambleServiceProvider::class);
    }

    /**
     * Bootstrap any application services.
     *
     * Password emails are sent with App\Mail\ForgotPasswordMail and
     * App\Mail\PasswordResetSuccessMail from the controllers.
     */
    public function boot(): void
    {
        //
    }
}
