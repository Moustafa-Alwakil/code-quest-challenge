<?php

declare(strict_types=1);

use App\Enums\TransferStatus;
use App\Exceptions\ProviderTimeoutException;
use App\Exceptions\ProviderUnavailableException;
use App\Models\MockProviderTransfer;
use App\Services\MockProviderStore;
use App\Services\PaymentProvider;
use App\Services\RandomMockProvider;
use App\Services\ScriptedMockProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * The mock provider is test infrastructure, and it carries a required item of
 * its own — so it gets tested like production code.
 *
 * If its dedup were wrong, every retry test in the suite would pass for the
 * wrong reason: the application would look safe because the mock was
 * forgiving, not because the design is. These are the tests that stop that.
 */

it('returns the original result rather than transferring again', function (): void {
    $first = provider()->transfer('key-dedup-1', 'acct_1', 50_000, 'EGP');
    $second = provider()->transfer('key-dedup-1', 'acct_1', 50_000, 'EGP');

    expect($first->status)->toBe(TransferStatus::SUCCEEDED)
        ->and($second->status)->toBe(TransferStatus::SUCCEEDED)
        ->and($second->providerReference)->toBe($first->providerReference)
        /** Asked twice, moved money once — the contract retries depend on. */
        ->and(provider()->callCount('key-dedup-1'))->toBe(2)
        ->and(provider()->transferCount('key-dedup-1'))->toBe(1)
        ->and(MockProviderTransfer::query()->where('idempotency_key', 'key-dedup-1')->count())->toBe(1);
});

it('does not consume a scripted outcome on a deduped call', function (): void {
    provider()->script([
        ScriptedMockProvider::OUTCOME_SUCCESS,
        ScriptedMockProvider::OUTCOME_PERMANENT_FAILURE,
    ]);

    provider()->transfer('key-script-1', 'acct_1', 10_000, 'EGP');

    /** The repeat is answered from the store, so the failure is still queued. */
    expect(provider()->transfer('key-script-1', 'acct_1', 10_000, 'EGP')->status)
        ->toBe(TransferStatus::SUCCEEDED);

    expect(provider()->transfer('key-script-2', 'acct_1', 10_000, 'EGP')->status)
        ->toBe(TransferStatus::FAILED);
});

it('records the transfer before throwing a timeout', function (): void {
    provider()->script([ScriptedMockProvider::OUTCOME_TIMEOUT_AFTER_SUCCESS]);

    expect(fn () => provider()->transfer('key-timeout-1', 'acct_1', 25_000, 'EGP'))
        ->toThrow(ProviderTimeoutException::class);

    /**
     * The money moved and the answer was lost — which is why `getStatus` can
     * still find it, and why treating a timeout as a failure would be wrong.
     */
    expect(provider()->transferCount('key-timeout-1'))->toBe(1)
        ->and(provider()->getStatus('key-timeout-1')->status)->toBe(TransferStatus::SUCCEEDED);
});

it('records nothing when it was never reachable', function (): void {
    provider()->script([ScriptedMockProvider::OUTCOME_UNAVAILABLE]);

    expect(fn () => provider()->transfer('key-down-1', 'acct_1', 25_000, 'EGP'))
        ->toThrow(ProviderUnavailableException::class);

    /** Provably not sent: nothing to find, so resending the key is safe. */
    expect(provider()->transferCount('key-down-1'))->toBe(0)
        ->and(provider()->getStatus('key-down-1')->status)->toBe(TransferStatus::NOT_FOUND);
});

it('reports a key it has never seen as not found, not as failed', function (): void {
    expect(provider()->getStatus('key-never-sent')->status)->toBe(TransferStatus::NOT_FOUND);
});

it('confirms a delayed transfer after the scripted number of checks', function (): void {
    provider()->script([ScriptedMockProvider::OUTCOME_DELAYED_CONFIRMATION]);

    expect(provider()->transfer('key-delayed-1', 'acct_1', 25_000, 'EGP')->status)
        ->toBe(TransferStatus::PENDING)
        ->and(provider()->getStatus('key-delayed-1')->status)->toBe(TransferStatus::PENDING)
        /** Video scenario 5: it flips on the provider's schedule, not ours. */
        ->and(provider()->getStatus('key-delayed-1')->status)->toBe(TransferStatus::SUCCEEDED)
        ->and(provider()->transferCount('key-delayed-1'))->toBe(1);
});

it('refuses to be called inside a transaction we opened', function (): void {
    /**
     * Resolved first, so its baseline is this test's transaction depth. The
     * provider records that depth when it is constructed — resolving it from
     * inside the transaction below would make the transaction *its* baseline
     * and neuter the guard. Every real path resolves it outside one: the
     * Action that injects it is built by a job or a command.
     */
    $provider = provider();

    expect(fn () => DB::transaction(fn () => $provider->transfer('key-txn-1', 'acct_1', 1_000, 'EGP')))
        ->toThrow(RuntimeException::class, 'was called inside a database transaction');

    /** And nothing was recorded, because the guard fires before any work. */
    expect(provider()->transferCount('key-txn-1'))->toBe(0);
});

it('dedups in the random provider too, so the demo is safe to re-run', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $random = new RandomMockProvider(
        app(MockProviderStore::class),
        ['success' => 100, 'permanent_failure' => 0, 'timeout_after_success' => 0, 'delayed_confirmation' => 0],
        2,
    );

    $first = $random->transfer('key-random-1', 'acct_1', 40_000, 'EGP');
    $second = $random->transfer('key-random-1', 'acct_1', 40_000, 'EGP');

    expect($first->providerReference)->toBe($second->providerReference)
        ->and(app(MockProviderStore::class)->transferCount('key-random-1'))->toBe(1);
});

it('rejects outcome weights that do not sum to one hundred', function (): void {
    $random = new RandomMockProvider(
        app(MockProviderStore::class),
        ['success' => 50, 'permanent_failure' => 0, 'timeout_after_success' => 0, 'delayed_confirmation' => 0],
        2,
    );

    /** A distribution that does not add up is a config bug, not something to round. */
    expect(fn () => $random->transfer('key-random-2', 'acct_1', 1_000, 'EGP'))
        ->toThrow(InvalidArgumentException::class, 'must sum to 100');
});

it('binds the provider the configuration names', function (): void {
    expect(app(PaymentProvider::class))->toBeInstanceOf(ScriptedMockProvider::class);

    config(['revenue.payout_provider' => 'nonsense']);
    app()->forgetInstance(PaymentProvider::class);

    expect(fn () => app(PaymentProvider::class))
        ->toThrow(InvalidArgumentException::class, "Unknown revenue.payout_provider 'nonsense'");
});
