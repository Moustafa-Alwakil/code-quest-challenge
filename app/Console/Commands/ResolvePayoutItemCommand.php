<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Payouts\ResolvePayoutItemAction;
use App\DTOs\Payouts\ResolvePayoutItemData;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * `payouts:resolve` — record what a person found out about a payout nobody
 * could resolve automatically (F08).
 *
 * The way out of `needs_review`, and the only place in this system where money
 * moves on a human's word. It exists so that the answer is "look it up and tell
 * the system" rather than "edit the database", which is the difference between
 * an audited correction and an untraceable one.
 *
 * The operator is expected to have established the outcome first — from the
 * provider's dashboard, a statement, or a support ticket. The `--reason` is
 * required and lands in `payout_attempts` beside the provider's own answers.
 */
final class ResolvePayoutItemCommand extends Command
{
    protected $signature = 'payouts:resolve
                            {item : The payout item to resolve}
                            {--as= : What actually happened: succeeded or failed}
                            {--reason= : Why you know; recorded in the audit trail}
                            {--provider-ref= : The provider reference, if the transfer succeeded}';

    protected $description = 'Resolve a payout item that is waiting on a human decision';

    public function handle(ResolvePayoutItemAction $resolvePayoutItem): int
    {
        $as = $this->option('as');
        $reason = $this->option('reason');
        $providerRef = $this->option('provider-ref');

        try {
            $data = ResolvePayoutItemData::fromCommand(
                (string) $this->argument('item'),
                is_string($as) ? $as : null,
                is_string($reason) ? $reason : null,
                is_string($providerRef) ? $providerRef : null,
            );

            $resolved = $resolvePayoutItem($data);
        } catch (InvalidArgumentException $invalid) {
            $this->error($invalid->getMessage());

            return self::INVALID;
        }

        if (! $resolved) {
            /**
             * The compare-and-swap found the item already moved between the
             * status check and the write — a reconciliation sweep getting there
             * first, which is the good outcome.
             *
             * Defensive, and deliberately not faked in a test: staging it needs
             * another process to commit inside a window of microseconds, and a
             * test that mocked its way there would be asserting the mock. The
             * guarantee is the CAS, which is exercised everywhere else.
             */
            $this->warn(sprintf(
                'Payout item %d resolved itself before this ran; nothing was changed.',
                $data->payoutItemId,
            ));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Payout item %d recorded as %s: %s',
            $data->payoutItemId,
            $data->outcome->value,
            $data->reason,
        ));

        $this->info($data->outcome->value === 'succeeded'
            ? 'The reservation has left the platform as cash.'
            : "The reservation has returned to the instructor's available balance.");

        return self::SUCCESS;
    }
}
