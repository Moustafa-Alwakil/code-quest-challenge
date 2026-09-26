<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Accrual\AccrueRevenueAction;
use App\DTOs\Accrual\AccrueRevenueData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * `ledger:accrue` — recognize every period whose term has closed, then release
 * the earnings whose hold has expired (F05).
 *
 * Scheduled daily, and unlike `subscriptions:expire` this one moves money, so
 * a future `--date` is refused by the DTO: you cannot recognize time that has
 * not happened. A past date is a backfill and is allowed.
 *
 * An entry point and nothing more — it parses options into a DTO, invokes the
 * action, and reports what happened.
 */
final class AccrueRevenueCommand extends Command
{
    /**
     * How long the advisory lock is held before it expires on its own, so a
     * crashed run cannot block tomorrow's.
     */
    private const LOCK_SECONDS = 3600;

    protected $signature = 'ledger:accrue
                            {--date= : Recognize periods closed on or before this date (YYYY-MM-DD); defaults to today}
                            {--chunk= : Periods per chunk (default 1000)}
                            {--sync : Recognize inline instead of dispatching chunk jobs}';

    protected $description = 'Recognize closed accrual periods and release matured earnings';

    public function handle(AccrueRevenueAction $accrueRevenue): int
    {
        $date = $this->option('date');
        $chunk = $this->option('chunk');

        try {
            $data = AccrueRevenueData::fromCommand(
                is_string($date) ? $date : null,
                is_string($chunk) ? $chunk : null,
                (bool) $this->option('sync'),
            );
        } catch (InvalidArgumentException $invalid) {
            $this->error($invalid->getMessage());

            return self::INVALID;
        }

        /**
         * An optimization, not a correctness guard. If the cache were gone, two
         * concurrent runs would still recognize each period exactly once — the
         * compare-and-set on `accrual_periods.status` does that. This only
         * saves the second run from doing the work to discover it.
         */
        $lock = Cache::lock('ledger:accrue', self::LOCK_SECONDS);

        if (! $lock->get()) {
            $this->info('ledger:accrue is already running; this run did nothing.');

            return self::SUCCESS;
        }

        try {
            $summary = $accrueRevenue($data);
        } finally {
            $lock->release();
        }

        $this->info(sprintf(
            'ledger:accrue found %d due period(s) as of %s.',
            $summary->periodsFound,
            $data->asOf->toDateString(),
        ));

        if ($data->sync) {
            $this->info(sprintf(
                'Recognized %d, skipped %d already recognized or cancelled, wrote %d allocation(s).',
                $summary->periodsRecognized,
                $summary->periodsSkipped,
                $summary->allocationsWritten,
            ));
        } else {
            $this->info(sprintf('Dispatched %d period(s) to chunk jobs.', $summary->periodsDispatched));
        }

        $this->info(sprintf('Released %d matured allocation(s) from hold.', $summary->earningsReleased));

        return self::SUCCESS;
    }
}
