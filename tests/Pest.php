<?php

declare(strict_types=1);

use App\Actions\Ledger\VerifyLedgerAction;
use App\DTOs\Ledger\VerifyLedgerData;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
 * Concurrency tests are deliberately *not* transaction-wrapped (R11, F11).
 * RefreshDatabase holds one transaction open for the whole test, so a second
 * connection could never see the first's rows and the race under test would
 * never happen — the test would pass for the wrong reason. DatabaseTruncation
 * commits, at the cost of speed, which is why these sit in their own group.
 */
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->group('concurrency')
    ->in('Concurrency');

/*
 * The chaos test drives the whole system through real entry points, so it needs
 * the ordinary transactional reset — and its own group, because it is the one
 * test worth running alone when something is wrong.
 */
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->group('chaos')
    ->in('Chaos');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Seed for property-based tests, so a failing run reproduces exactly.
 *
 * Set TEST_SEED to replay a specific run; the seed is printed with any failure.
 */
function testSeed(): int
{
    $seed = $_ENV['TEST_SEED'] ?? getenv('TEST_SEED');

    return is_string($seed) && $seed !== '' ? (int) $seed : 20260923;
}

/**
 * The project currency, in minor units — every amount in these tests is an int.
 */
function egp(int $minor): App\Support\Money
{
    return App\Support\Money::of($minor, 'EGP');
}

/**
 * The provider the suite is bound to, as the scripted mock it always is.
 *
 * `phpunit.xml` pins PAYOUT_PROVIDER to `scripted`, so every test gets a
 * provider whose next answer it can choose. Its `transferCount()` is the source
 * of truth for "the money moved once", and its `callCount()` for "we asked more
 * than once and the dedup absorbed it".
 */
function provider(): App\Services\ScriptedMockProvider
{
    $provider = app(App\Services\PaymentProvider::class);

    if (! $provider instanceof App\Services\ScriptedMockProvider) {
        throw new RuntimeException('The suite expects the scripted provider; check PAYOUT_PROVIDER in phpunit.xml.');
    }

    return $provider;
}

/**
 * Runs the `ledger:verify` checks in-process (F11's invariant hook).
 *
 * Registered in `afterEach` by every money-touching test file, so each of those
 * tests implicitly proves invariants I1-I4 as well as whatever it was written
 * for: the ledger sums to zero, every transaction sums to zero, every snapshot
 * field equals its recomputed value, and `outstanding = available + held +
 * reserved` for every instructor.
 *
 * A test that deliberately corrupts the ledger — the `ledger:verify` red path —
 * is the one place this must not be registered.
 */
/**
 * The same checks as a boolean, for tests whose subject *is* a broken ledger.
 *
 * `assertLedgerBalanced()` is an assertion and cannot be negated; a test that
 * deliberately corrupts the ledger still has to prove the verifier noticed.
 */
function ledgerIsClean(?int $instructorId = null): bool
{
    return app(VerifyLedgerAction::class)(
        VerifyLedgerData::fromCommand($instructorId === null ? null : (string) $instructorId, failFast: false)
    )->isClean();
}

function assertLedgerBalanced(): void
{
    $result = app(VerifyLedgerAction::class)(VerifyLedgerData::everything());

    $report = array_map(
        static fn (array $row): string => sprintf(
            '  %s | %s | %s | expected %s, got %s',
            $row['check'],
            $row['scope'],
            $row['field'],
            $row['expected'],
            $row['actual'],
        ),
        $result->toRows(),
    );

    Assert::assertTrue(
        $result->isClean(),
        "The ledger and the balance snapshot disagree:\n".implode("\n", $report),
    );
}

/*
|--------------------------------------------------------------------------
| Shared domain helpers
|--------------------------------------------------------------------------
|
| These live here rather than in whichever test file first needed them, because
| they are used across every suite and a function defined inside a Feature test
| is invisible to `--testsuite=Chaos` or `--testsuite=Concurrency` run on their
| own. That failure mode is quiet and confusing: the suite reports an undefined
| function from a file that has nothing to do with the problem.
|
*/

/**
 * The capture moment used throughout: 23:00 on a month end, in a leap year.
 *
 * Both halves matter — the time proves a purchase late in the day still gets
 * whole days, and Jan 31 is the date a chained schedule gets wrong (R10).
 */
function capturedAt(): Carbon\CarbonImmutable
{
    return Carbon\CarbonImmutable::parse('2024-01-31 23:00:00');
}

/**
 * A term, through the real entry point — never a factory.
 *
 * A factory-made subscription has no payment, no ledger entries and no
 * schedule, so any test that asserts on balances has to come through here
 * (F02, Factories).
 */
function recordCapturedPayment(
    App\Models\User $user,
    App\Models\Plan $plan,
    string $externalRef = 'ch_live_0001',
    ?int $amountMinor = null,
    ?string $currency = null,
    ?Carbon\CarbonImmutable $capturedAt = null,
): App\Support\Subscriptions\SubscriptionOutcome {
    return app(App\Actions\Subscriptions\SubscribeStudentAction::class)(
        App\DTOs\Subscriptions\SubscribeStudentData::forCapturedPayment(
            userId: $user->id,
            planId: $plan->id,
            externalRef: $externalRef,
            amountMinor: $amountMinor ?? $plan->price_minor,
            currency: $currency ?? $plan->currency,
            capturedAt: $capturedAt ?? capturedAt(),
        )
    );
}

/**
 * The raw signed sum of one account's entries.
 *
 * Signed, not "owed": liabilities are credit-normal, so a payable that owes
 * 1 000 sums to −1 000. Tests say which they mean.
 */
function ledgerSumFor(App\Enums\LedgerAccountType $accountType, int $accountId): int
{
    return (int) App\Models\LedgerEntry::query()
        ->where('account_type', $accountType)
        ->where('account_id', $accountId)
        ->sum('amount_minor');
}

/**
 * One engagement rollup row: this instructor, this period, these minutes.
 */
function engage(int $subscriptionId, App\Models\AccrualPeriod $period, App\Models\Instructor $instructor, int $units): void
{
    App\Models\Engagement::query()->create([
        'subscription_id' => $subscriptionId,
        'period_start' => $period->period_start->toDateString(),
        'instructor_id' => $instructor->id,
        'units' => $units,
    ]);
}

/**
 * Sends one reserved payout, as a worker would.
 */
function processItem(int $payoutItemId): App\Enums\PayoutItemStatus
{
    return app(App\Actions\Payouts\ProcessPayoutItemAction::class)($payoutItemId);
}
