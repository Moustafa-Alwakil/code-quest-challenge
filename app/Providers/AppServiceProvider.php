<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\MockProviderStore;
use App\Services\PaymentProvider;
use App\Services\RandomMockProvider;
use App\Services\ScriptedMockProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PaymentProvider::class, function (): PaymentProvider {
            $configured = config('revenue.payout_provider');

            return match ($configured) {
                'scripted' => $this->app->make(ScriptedMockProvider::class),
                'random', 'mock' => $this->randomProvider(),
                default => throw new InvalidArgumentException(
                    "Unknown revenue.payout_provider '".(is_string($configured) ? $configured : get_debug_type($configured))."'."
                ),
            };
        });
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

    /**
     * The demo provider, with its behaviour weights read here rather than
     * inside the provider: `App\Services` may reach for config, but keeping the
     * dial at the binding means a test can swap the whole provider without
     * having to know what it reads.
     */
    private function randomProvider(): RandomMockProvider
    {
        $outcomes = config('revenue.provider_outcomes');
        $confirmAfter = config('revenue.provider_confirm_after_checks');

        if (! is_array($outcomes) || ! is_int($confirmAfter)) {
            throw new InvalidArgumentException('revenue.provider_outcomes must be an array and provider_confirm_after_checks an integer.');
        }

        /** @var array{success: int, permanent_failure: int, timeout_after_success: int, delayed_confirmation: int} $outcomes */
        return new RandomMockProvider($this->app->make(MockProviderStore::class), $outcomes, $confirmAfter);
    }
}
