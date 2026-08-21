<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
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
        // Phase 8B-2: re-points Laravel's built-in password-reset
        // notification at the Flutter Web reset screen instead of its
        // default fallback (a `password.reset` named route this
        // API-only project never registers, since there is no
        // server-rendered reset form). This is the *only* thing
        // customized here -- token generation/hashing/expiration/
        // single-use enforcement all stay Laravel's own untouched
        // `Password` broker behavior. Mirrors `EmailService`'s existing
        // convention of building absolute frontend links from
        // `config('app.frontend_url')`, never a hardcoded host.
        ResetPassword::createUrlUsing(function ($notifiable, string $token) {
            $email = urlencode($notifiable->getEmailForPasswordReset());

            return rtrim((string) config('app.frontend_url'), '/')
                ."/reset-password?token={$token}&email={$email}";
        });
    }
}
