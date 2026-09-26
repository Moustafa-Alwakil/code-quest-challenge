<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Refunds\IssueRefundAction;
use App\DTOs\Refunds\IssueRefundData;
use App\Support\Money;
use App\Support\Refunds\RefundOutcome;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * `refunds:issue` — record a refund the gateway has already executed, and
 * apply its consequences (F09, R25).
 *
 * `--dry-run` prints the refund and the per-instructor impact through the same
 * calculation the real run uses, so the preview is the number applied. For the
 * video that matters: the pro-rata preview shows a column of zeroes against
 * every instructor, which is the point being made.
 */
final class IssueRefundCommand extends Command
{
    protected $signature = 'refunds:issue
                            {subscription : The subscription to refund}
                            {--external-ref= : The gateway\'s refund id; refunds are keyed by it}
                            {--full : Refund the whole price, clawing back earnings}
                            {--effective= : The date access ends (YYYY-MM-DD); defaults to today}
                            {--dry-run : Print the refund and its impact, writing nothing}
                            {--reason= : Free text recorded with the refund}';

    protected $description = 'Record a refund and apply it to the schedule and the ledger';

    public function handle(IssueRefundAction $issueRefund): int
    {
        $externalRef = $this->option('external-ref');
        $effective = $this->option('effective');
        $reason = $this->option('reason');

        try {
            $data = IssueRefundData::fromCommand(
                (string) $this->argument('subscription'),
                is_string($externalRef) ? $externalRef : '',
                (bool) $this->option('full'),
                is_string($effective) ? $effective : null,
                (bool) $this->option('dry-run'),
                is_string($reason) ? $reason : null,
            );

            $outcome = $issueRefund($data);
        } catch (InvalidArgumentException $invalid) {
            $this->error($invalid->getMessage());

            return self::INVALID;
        }

        if ($outcome->replayed) {
            $this->info(sprintf('Subscription %d is already refunded; nothing was written.', $data->subscriptionId));

            return self::SUCCESS;
        }

        $this->report($outcome, $data);

        return self::SUCCESS;
    }

    private function report(RefundOutcome $outcome, IssueRefundData $data): void
    {
        $currency = 'EGP';

        $this->info(sprintf(
            '%s %s refund for subscription %d, effective %s.',
            $outcome->applied ? 'Applied' : 'Would apply a',
            $outcome->type->value,
            $data->subscriptionId,
            $data->effectiveAt->toDateString(),
        ));

        $this->info(sprintf(
            'Refund %s: %s unearned, %s clawed back from instructors, %s from platform revenue.',
            Money::of($outcome->refundMinor, $currency)->format(),
            Money::of($outcome->unearnedMinor, $currency)->format(),
            Money::of($outcome->clawedBackMinor, $currency)->format(),
            Money::of($outcome->platformClawedBackMinor, $currency)->format(),
        ));

        $this->info(sprintf(
            '%d period(s) cancelled%s.',
            $outcome->periodsCancelled,
            $outcome->truncatedAPeriod ? ', one truncated and recognized' : '',
        ));

        /**
         * The empty table is the headline of scenario 6: a student leaving
         * mid-term costs no instructor anything, because nobody was ever
         * credited for the months they did not use.
         */
        if ($outcome->clawedBackByInstructor === []) {
            $this->info('No instructor is affected.');

            return;
        }

        $this->table(
            ['Instructor', 'Clawed back'],
            array_map(
                static fn (int $instructorId, int $amount): array => [
                    $instructorId,
                    Money::of($amount, $currency)->format(),
                ],
                array_keys($outcome->clawedBackByInstructor),
                $outcome->clawedBackByInstructor,
            ),
        );
    }
}
