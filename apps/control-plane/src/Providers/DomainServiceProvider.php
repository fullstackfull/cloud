<?php

declare(strict_types=1);

namespace Lynomia\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

/**
 * Cross-cutting framework configuration for the domain modules.
 *
 * Everything here is a global default that must hold for every module, so it
 * lives in one place rather than being repeated in twenty-eight module
 * providers.
 */
final class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Domain models live under Lynomia\Modules\<Module>\Infrastructure\Models
        // while their factories live in the conventional Database\Factories
        // namespace. Teach Laravel that mapping once instead of overriding
        // newFactory() on every model.
        Factory::guessFactoryNamesUsing(
            static fn (string $modelName): string => 'Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        Factory::guessModelNamesUsing(
            static fn (Factory $factory): string => throw new \LogicException(
                'Factories in this application must declare their $model explicitly; '
                .'guessing is ambiguous across modules.'
            )
        );
    }

    public function boot(): void
    {
        // Accessing an un-eager-loaded relation is a performance bug. Fail in
        // development and testing so it is caught before it reaches production
        // traffic, where it degrades rather than breaks.
        Model::preventLazyLoading(! $this->app->isProduction());

        // Silently discarding an attribute because it was not fillable hides
        // real bugs; make it an exception outside production.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Immutable dates everywhere: a Carbon instance mutated in place is a
        // classic source of billing-period bugs.
        Date::use(CarbonImmutable::class);

        // One password policy for the whole platform.
        Password::defaults(fn (): Password => $this->app->isProduction()
            ? Password::min(12)->letters()->mixedCase()->numbers()->symbols()->uncompromised()
            : Password::min(8)->letters()->numbers());
    }
}
