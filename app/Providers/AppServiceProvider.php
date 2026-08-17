<?php

namespace App\Providers;

use App\Parser\Contracts\RequestSleeper;
use App\Parser\Notifications\AvailabilityIncidentNotifier;
use App\Parser\Notifications\NullAvailabilityIncidentNotifier;
use App\Parser\Services\NativeRequestSleeper;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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
        $this->app->bind(AvailabilityIncidentNotifier::class, NullAvailabilityIncidentNotifier::class);

        $this->app->bind(RequestSleeper::class, NativeRequestSleeper::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Gate::define('access-admin', fn ($user): bool => (bool) $user->is_admin);
        Model::preventLazyLoading(! app()->isProduction());
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
