<?php

declare(strict_types=1);

namespace App\Providers;

use App\Queue\RefuseAWorkerThatWouldRunAJobTwice;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
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
        /*
         * A worker whose timeout outlives its connection's retry clock runs
         * jobs twice at once (F-08). The clocks are environment-overridable,
         * so this is checked where they are finally resolved, as the worker
         * starts. Laravel only raises CommandStarting outside unit tests.
         */
        Event::listen(CommandStarting::class, RefuseAWorkerThatWouldRunAJobTwice::class);
    }
}
