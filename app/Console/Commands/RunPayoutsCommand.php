<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Payouts\RunPayoutsAction;
use App\DTOs\Payouts\RunPayoutsData;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * `payouts:run` — reserve every payable balance for one run key (F06).
 *
 * The run key is the idempotency key of the whole command. Invoking it twice
 * for `payout:2026-09` resumes one run; a different key opens a new one over
 * whatever has matured since.
 *
 * An entry point and nothing more: options into a DTO, invoke the Action,
 * report. The lock below is the only thing it decides for itself, and it
 * decides nothing about money.
 */
final class RunPayoutsCommand extends Command
{
    /**
     * Long enough for a large run, short enough that a crashed invocation does
     * not block the next one for a day.
     */
    private const LOCK_SECONDS = 3600;

    protected $signature = 'payouts:run
                            {--run-key= : The run to create or resume; defaults to payout:YYYY-MM}
                            {--min-amount= : Minimum payable balance in minor units; defaults to revenue.minimum_payout_minor}
                            {--dry-run : Print what would be reserved and write nothing}
                            {--sync : Reserve inline instead of dispatching work}';

    protected $description = 'Reserve every payable instructor balance for a payout run';

    public function handle(RunPayoutsAction $runPayouts): int
    {
        $runKey = $this->option('run-key');
        $minAmount = $this->option('min-amount');

        try {
            $data = RunPayoutsData::fromCommand(
                is_string($runKey) ? $runKey : null,
                is_string($minAmount) ? $minAmount : null,
                (bool) $this->option('dry-run'),
                (bool) $this->option('sync'),
            );
        } catch (InvalidArgumentException $invalid) {
            $this->error($invalid->getMessage());

            return self::INVALID;
        }

        /**
         * An optimization, and the demo says so out loud. If the cache were
         * gone, two concurrent invocations would still produce one run and one
         * item per instructor — UNIQUE `run_key` and UNIQUE
         * `(payout_run_id, instructor_id)` do that, and reservation stops a
         * later run seeing money that has already left `available`. This only
         * saves the second invocation from doing the work to find out.
         */
        $lock = Cache::lock($data->lockKey(), self::LOCK_SECONDS);

        if (! $lock->get()) {
            $this->info("payouts:run is already running for {$data->runKey}; this invocation did nothing.");

            return self::SUCCESS;
        }

        try {
            $summary = $runPayouts($data);
        } finally {
            $lock->release();
        }

        if ($summary->dryRun) {
            $this->info(sprintf(
                'payouts:run --dry-run for %s would reserve %d instructor(s) totalling %s. Nothing was written.',
                $summary->runKey,
                $summary->reserved,
                Money::of($summary->reservedMinor, $data->currency)->format(),
            ));

            return self::SUCCESS;
        }

        if ($summary->alreadyFinished) {
            $this->info(sprintf(
                'payouts:run %s is already %s; nothing to do. Use a new --run-key to pay newly matured earnings.',
                $summary->runKey,
                $summary->status->value,
            ));

            return self::SUCCESS;
        }

        $this->info(sprintf('payouts:run %s released %d matured allocation(s).', $summary->runKey, $summary->released));

        $this->info(sprintf(
            'Considered %d balance(s): reserved %d totalling %s, skipped %d.',
            $summary->considered,
            $summary->reserved,
            Money::of($summary->reservedMinor, $data->currency)->format(),
            $summary->skipped,
        ));

        $this->info(sprintf(
            '%d item(s) awaiting a worker; run status %s.',
            $summary->dispatchable,
            $summary->status->value,
        ));

        return self::SUCCESS;
    }
}
