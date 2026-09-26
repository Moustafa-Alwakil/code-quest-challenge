<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Cosmetic housekeeping, not a money job (F04): terms that ended overnight are
 * marked expired. Nothing financial depends on it running, which is why it needs
 * no overlap guard — the conditional UPDATE behind it is idempotent.
 */
Schedule::command('subscriptions:expire')->dailyAt('00:10');

/*
 * The money job (F05): every period whose term closed overnight becomes
 * platform revenue plus instructor earnings, and earnings past their hold
 * become payable. Runs after the expiry sweep, though nothing depends on the
 * order — recognition is driven by `accrual_periods`, never by a subscription's
 * status.
 *
 * Missing a night costs nothing and is not a correctness problem: the next run
 * recognizes everything still `scheduled`, including yesterday's. It is a
 * reporting delay, not lost money.
 */
Schedule::command('ledger:accrue')->dailyAt('00:20');

/*
 * Payouts (F06), monthly on the 1st. The default run key is `payout:YYYY-MM`,
 * so a manual re-trigger during the month resumes this run rather than opening
 * a second one.
 *
 * Both guards are optimizations, and the suite proves it: `withoutOverlapping`
 * and `onOneServer` need the shared cache, and if it vanished the UNIQUE run
 * key, the UNIQUE (run, instructor) pair and reserve-before-send would still
 * leave exactly one payment per instructor.
 */
Schedule::command('payouts:run')
    ->monthlyOn(1, '03:00')
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Reconciliation (F08). Every five minutes, because an `unknown` payout has
 * money frozen in `provider_in_transit` and the only way to unfreeze it is to
 * ask the provider again.
 *
 * `withoutOverlapping` is an optimization like every other lock here: two
 * concurrent sweeps would still settle each item once, because the status
 * compare-and-swap and the ledger's unique key decide, not the lock.
 */
Schedule::command('payouts:reconcile')
    ->everyFiveMinutes()
    ->withoutOverlapping();
