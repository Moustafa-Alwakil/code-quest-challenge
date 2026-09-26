<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts;
use App\Contracts\PaymentProvider;
use App\Services\AccrualService;
use App\Services\EarningAllocationService;
use App\Services\EngagementService;
use App\Services\InstructorBalanceService;
use App\Services\LedgerService;
use App\Services\LedgerVerificationService;
use App\Services\MockProviderStore;
use App\Services\PayoutItemService;
use App\Services\PayoutRunService;
use App\Services\PlanService;
use App\Services\RandomMockProvider;
use App\Services\RefundService;
use App\Services\ScriptedMockProvider;
use App\Services\SubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    /**
     * Every aggregate is reached through its contract, never its class.
     *
     * Two reasons, and the second is the one that bites. Swapping a persistence
     * implementation is the advertised benefit and the rarer one. The everyday
     * benefit is that R14's promise — "every Action unit-testable against a
     * faked Service" — was unreachable while the services were `final`: PHPUnit
     * cannot double a final class, so an Action's collaborators could only ever
     * be the real thing talking to a real database.
     *
     * Bound as singletons because every one of them is stateless: they hold
     * queries, not data.
     *
     * @var array<class-string, class-string>
     */
    private const AGGREGATES = [
        Contracts\AccrualServiceContract::class => AccrualService::class,
        Contracts\EarningAllocationServiceContract::class => EarningAllocationService::class,
        Contracts\EngagementServiceContract::class => EngagementService::class,
        Contracts\InstructorBalanceServiceContract::class => InstructorBalanceService::class,
        Contracts\LedgerServiceContract::class => LedgerService::class,
        Contracts\LedgerVerificationServiceContract::class => LedgerVerificationService::class,
        Contracts\MockProviderStoreContract::class => MockProviderStore::class,
        Contracts\PayoutItemServiceContract::class => PayoutItemService::class,
        Contracts\PayoutRunServiceContract::class => PayoutRunService::class,
        Contracts\PlanServiceContract::class => PlanService::class,
        Contracts\RefundServiceContract::class => RefundService::class,
        Contracts\SubscriptionServiceContract::class => SubscriptionService::class,
    ];

    public function register(): void
    {
        foreach (self::AGGREGATES as $contract => $implementation) {
            $this->app->singleton($contract, $implementation);
        }

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
        return new RandomMockProvider($this->app->make(Contracts\MockProviderStoreContract::class), $outcomes, $confirmAfter);
    }
}
