<?php

declare(strict_types=1);

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
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
        /**
         * Dates are immutable everywhere, including Eloquent attributes (R22).
         *
         * Without this, `$model->created_at` is a mutable Illuminate\Support\
         * Carbon while every model annotates CarbonImmutable — and PHPStan
         * trusts the annotation, so F04's `term_start + k months` period
         * boundaries (R10) could mutate an attribute in place with nothing
         * reported. A period schedule that drifts by a day is a money bug.
         */
        Date::use(CarbonImmutable::class);
    }
}
