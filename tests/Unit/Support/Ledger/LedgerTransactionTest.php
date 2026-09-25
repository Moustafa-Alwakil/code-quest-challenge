<?php

declare(strict_types=1);

use App\Enums\LedgerAccountType;
use App\Enums\LedgerEntryType;
use App\Exceptions\CurrencyMismatchException;
use App\Exceptions\InvalidLedgerTransactionException;
use App\Support\Ledger\LedgerLeg;
use App\Support\Ledger\LedgerTransaction;
use App\Support\Money;

/*
 * Every rule here is checked before a row is written, so an invalid transaction
 * never reaches the table. No database: these are pure value objects.
 */
it('builds a balanced transaction and keeps the legs in order', function (): void {
    $transaction = LedgerTransaction::of(
        LedgerEntryType::PERIOD_RECOGNIZED,
        'accrual_period',
        42,
        LedgerLeg::debit(LedgerAccountType::DEFERRED_REVENUE, 7, egp(10_000)),
        LedgerLeg::credit(LedgerAccountType::PLATFORM_REVENUE, 0, egp(3_000)),
        LedgerLeg::credit(LedgerAccountType::INSTRUCTOR_PAYABLE, 3, egp(7_000)),
    );

    expect($transaction->legCount())->toBe(3)
        ->and($transaction->currency)->toBe('EGP')
        ->and($transaction->referenceId)->toBe(42)
        ->and($transaction->referenceType)->toBe('accrual_period')
        ->and(array_map(fn (LedgerLeg $leg): int => $leg->amount->minor, $transaction->legs))
        ->toBe([10_000, -3_000, -7_000]);
});

it('negates a credit so callers never write a negative amount', function (): void {
    $credit = LedgerLeg::credit(LedgerAccountType::PLATFORM_CASH, 0, egp(2_500));
    $debit = LedgerLeg::debit(LedgerAccountType::PLATFORM_CASH, 0, egp(2_500));

    expect($credit->amount->minor)->toBe(-2_500)
        ->and($credit->isCredit())->toBeTrue()
        ->and($debit->amount->minor)->toBe(2_500)
        ->and($debit->isDebit())->toBeTrue();
});

it('rejects a leg whose amount already carries a sign', function (): void {
    expect(fn () => LedgerLeg::credit(LedgerAccountType::PLATFORM_CASH, 0, egp(-100)))
        ->toThrow(InvalidLedgerTransactionException::class, 'positive magnitudes');
});

it('rejects a transaction with a single leg', function (): void {
    expect(fn () => LedgerTransaction::of(
        LedgerEntryType::PAYMENT_RECEIVED,
        'payment',
        1,
        LedgerLeg::debit(LedgerAccountType::PLATFORM_CASH, 0, egp(10_000)),
    ))->toThrow(InvalidLedgerTransactionException::class, 'at least 2 legs, got 1');
});

it('rejects legs that do not sum to zero', function (): void {
    expect(fn () => LedgerTransaction::of(
        LedgerEntryType::PAYMENT_RECEIVED,
        'payment',
        1,
        LedgerLeg::debit(LedgerAccountType::PLATFORM_CASH, 0, egp(10_000)),
        LedgerLeg::credit(LedgerAccountType::DEFERRED_REVENUE, 1, egp(9_999)),
    ))->toThrow(InvalidLedgerTransactionException::class, 'sum to zero, got 1 EGP');
});

it('rejects legs in two currencies', function (): void {
    expect(fn () => LedgerTransaction::of(
        LedgerEntryType::PAYMENT_RECEIVED,
        'payment',
        1,
        LedgerLeg::debit(LedgerAccountType::PLATFORM_CASH, 0, egp(10_000)),
        LedgerLeg::credit(LedgerAccountType::DEFERRED_REVENUE, 1, Money::of(10_000, 'USD')),
    ))->toThrow(CurrencyMismatchException::class, 'EGP and USD');
});

it('rejects two legs on the same account, which the unique key would reject anyway', function (): void {
    expect(fn () => LedgerTransaction::of(
        LedgerEntryType::PERIOD_RECOGNIZED,
        'accrual_period',
        1,
        LedgerLeg::debit(LedgerAccountType::DEFERRED_REVENUE, 5, egp(10_000)),
        LedgerLeg::credit(LedgerAccountType::INSTRUCTOR_PAYABLE, 9, egp(4_000)),
        LedgerLeg::credit(LedgerAccountType::INSTRUCTOR_PAYABLE, 9, egp(6_000)),
    ))->toThrow(InvalidLedgerTransactionException::class, 'Two legs post to instructor_payable#9');
});

it('rejects a singleton account keyed with anything but zero', function (): void {
    expect(fn () => LedgerTransaction::of(
        LedgerEntryType::PAYMENT_RECEIVED,
        'payment',
        1,
        LedgerLeg::debit(LedgerAccountType::PLATFORM_CASH, 4, egp(10_000)),
        LedgerLeg::credit(LedgerAccountType::DEFERRED_REVENUE, 1, egp(10_000)),
    ))->toThrow(InvalidLedgerTransactionException::class, 'platform_cash is a singleton account');
});

it('rejects a per-row account keyed with the singleton sentinel', function (): void {
    expect(fn () => LedgerTransaction::of(
        LedgerEntryType::PAYMENT_RECEIVED,
        'payment',
        1,
        LedgerLeg::debit(LedgerAccountType::PLATFORM_CASH, 0, egp(10_000)),
        LedgerLeg::credit(LedgerAccountType::DEFERRED_REVENUE, 0, egp(10_000)),
    ))->toThrow(InvalidLedgerTransactionException::class, 'deferred_revenue is keyed by a business row');
});

it('rejects a reference id of zero or less, which would break the idempotency key', function (int $referenceId): void {
    expect(fn () => LedgerTransaction::of(
        LedgerEntryType::PAYMENT_RECEIVED,
        'payment',
        $referenceId,
        LedgerLeg::debit(LedgerAccountType::PLATFORM_CASH, 0, egp(10_000)),
        LedgerLeg::credit(LedgerAccountType::DEFERRED_REVENUE, 1, egp(10_000)),
    ))->toThrow(InvalidLedgerTransactionException::class, 'positive reference_id');
})->with([0, -1]);

it('rejects a blank reference type', function (): void {
    expect(fn () => LedgerTransaction::of(
        LedgerEntryType::PAYMENT_RECEIVED,
        '   ',
        1,
        LedgerLeg::debit(LedgerAccountType::PLATFORM_CASH, 0, egp(10_000)),
        LedgerLeg::credit(LedgerAccountType::DEFERRED_REVENUE, 1, egp(10_000)),
    ))->toThrow(InvalidLedgerTransactionException::class, 'non-empty reference_type');
});

it('allows a zero-amount leg, because an allocation can round to nothing', function (): void {
    $transaction = LedgerTransaction::of(
        LedgerEntryType::PERIOD_RECOGNIZED,
        'accrual_period',
        1,
        LedgerLeg::debit(LedgerAccountType::DEFERRED_REVENUE, 1, egp(10_000)),
        LedgerLeg::credit(LedgerAccountType::PLATFORM_REVENUE, 0, egp(10_000)),
        LedgerLeg::credit(LedgerAccountType::INSTRUCTOR_PAYABLE, 2, egp(0)),
    );

    expect($transaction->legCount())->toBe(3);
});

it('knows which accounts are singletons and which follow an instructor', function (): void {
    expect(LedgerAccountType::PLATFORM_CASH->isSingleton())->toBeTrue()
        ->and(LedgerAccountType::PLATFORM_REVENUE->isSingleton())->toBeTrue()
        ->and(LedgerAccountType::DEFERRED_REVENUE->isSingleton())->toBeFalse()
        ->and(LedgerAccountType::INSTRUCTOR_PAYABLE->isInstructorKeyed())->toBeTrue()
        ->and(LedgerAccountType::PROVIDER_IN_TRANSIT->isInstructorKeyed())->toBeTrue()
        ->and(LedgerAccountType::DEFERRED_REVENUE->isInstructorKeyed())->toBeFalse();
});
