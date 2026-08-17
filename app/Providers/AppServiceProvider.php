<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Listeners\RecordSuccessfulLogin;
use App\Models\User;
use App\Services\Monitoring\Contracts\DnsResolver;
use App\Services\Monitoring\Contracts\TlsInspector;
use App\Services\Monitoring\NativeDnsResolver;
use App\Services\Monitoring\NativeTlsInspector;
use App\Services\Whm\Contracts\WhmClient;
use App\Services\Whm\HttpWhmClient;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(DnsResolver::class, NativeDnsResolver::class);
        $this->app->bind(TlsInspector::class, NativeTlsInspector::class);
        $this->app->bind(WhmClient::class, HttpWhmClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Gate::define('admin', fn (User $user): bool => $user->enabled && $user->role === UserRole::Admin);
        Event::listen(Login::class, RecordSuccessfulLogin::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
