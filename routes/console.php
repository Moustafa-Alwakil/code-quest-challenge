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
