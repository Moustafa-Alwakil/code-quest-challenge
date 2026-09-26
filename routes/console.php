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
