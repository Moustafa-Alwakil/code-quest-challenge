<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Payouts\ReconcilePayoutsAction;
use App\DTOs\Payouts\ReconcilePayoutsData;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * `payouts:reconcile` — resolve every payout whose outcome is uncertain by
 * asking the provider (F08).
 *
 * Scheduled every five minutes. No lock of its own: the schedule's
 * `withoutOverlapping()` saves the wasted work, and correctness comes from the
 * per-item compare-and-swap and the ledger's unique key, both of which hold
 * whether one copy runs or five.
 */
final class ReconcilePayoutsCommand extends Command
{
    protected $signature = 'payouts:reconcile
                            {--limit= : Items per sweep (default 500)}
                            {--sync : Resolve inline instead of dispatching jobs}';

    protected $description = 'Ask the provider about payouts whose outcome is still uncertain';

    public function handle(ReconcilePayoutsAction $reconcilePayouts): int
    {
        $limit = $this->option('limit');

        try {
            $data = ReconcilePayoutsData::fromCommand(
                is_string($limit) ? $limit : null,
                (bool) $this->option('sync'),
            );
        } catch (InvalidArgumentException $invalid) {
            $this->error($invalid->getMessage());

            return self::INVALID;
        }

        $summary = $reconcilePayouts($data);

        $this->info(sprintf(
            'payouts:reconcile found %d uncertain and %d stranded item(s) as of %s.',
            $summary->uncertain,
            $summary->stranded,
            $data->asOf->toDateTimeString(),
        ));

        if ($summary->sync) {
            $this->info(sprintf('Resolved %d item(s) to a terminal state.', $summary->resolved));
        }

        return self::SUCCESS;
    }
}
