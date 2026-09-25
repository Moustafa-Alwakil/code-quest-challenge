<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Ledger\VerifyLedgerAction;
use App\DTOs\Ledger\VerifyLedgerData;
use Illuminate\Console\Command;

/**
 * `ledger:verify` — recompute everything, report every mismatch, exit 1 on any.
 *
 * The exit code is the point: this gates CI and alerts from the scheduler.
 *
 *   0 — the snapshot and the ledger agree.
 *   1 — the money is wrong. This is the only thing exit 1 ever means, so an
 *       alert on it never needs interpreting.
 *   2 — the invocation was useless: `--instructor=` named someone with no
 *       snapshot row, so nothing was checked (R21). Reporting success over zero
 *       rows is the failure mode the counts in the output exist to prevent.
 *
 * An entry point and nothing more — it parses options into a DTO, invokes the
 * action and renders the result.
 */
final class VerifyLedgerCommand extends Command
{
    protected $signature = 'ledger:verify
                            {--instructor= : Restrict to one instructor id (skips the ledger-wide sum checks)}
                            {--fail-fast : Stop at the first mismatch}';

    protected $description = 'Recompute the balance snapshot from the ledger and report any disagreement';

    public function handle(VerifyLedgerAction $verifyLedger): int
    {
        $instructor = $this->option('instructor');

        $data = VerifyLedgerData::fromCommand(
            is_string($instructor) ? $instructor : null,
            (bool) $this->option('fail-fast'),
        );

        $result = $verifyLedger($data);

        $scope = $data->instructorId === null
            ? 'the whole ledger'
            : "instructor {$data->instructorId}";

        if ($result->isClean() && $data->instructorId !== null && $result->instructorsChecked === 0) {
            $this->warn(sprintf(
                'ledger:verify checked nothing: instructor %d has no balance snapshot row. Nothing is proven either way.',
                $data->instructorId,
            ));

            return self::INVALID;
        }

        if ($result->isClean()) {
            $this->info(sprintf(
                'ledger:verify passed over %s: %d entries, %d transactions, %d instructor balances.',
                $scope,
                $result->entriesChecked,
                $result->transactionsChecked,
                $result->instructorsChecked,
            ));

            return self::SUCCESS;
        }

        $this->table(
            ['Check', 'Scope', 'Field', 'Expected', 'Actual', 'Delta'],
            $result->toRows(),
        );

        $this->error(sprintf(
            'ledger:verify FAILED over %s: %d mismatch(es)%s.',
            $scope,
            $result->mismatchCount(),
            $result->stoppedEarly ? ', stopped at the first (--fail-fast)' : '',
        ));

        return self::FAILURE;
    }
}
