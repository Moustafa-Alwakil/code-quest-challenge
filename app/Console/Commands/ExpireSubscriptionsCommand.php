<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Subscriptions\ExpireSubscriptionsAction;
use App\DTOs\Subscriptions\ExpireSubscriptionsData;
use Illuminate\Console\Command;

/**
 * `subscriptions:expire` — close off terms that have ended.
 *
 * Scheduled daily, and cosmetic by design: it moves a status column and nothing
 * else. No `--dry-run`, because there is no money to move; no lock, because the
 * conditional UPDATE it drives is idempotent whether one copy runs or five.
 *
 * An entry point and nothing more — it parses options into a DTO, invokes the
 * action and reports the count.
 */
final class ExpireSubscriptionsCommand extends Command
{
    protected $signature = 'subscriptions:expire
                            {--as-of= : Treat this date as today (YYYY-MM-DD); defaults to today}
                            {--chunk= : Rows per batch (default 1000)}';

    protected $description = 'Mark subscriptions whose term has ended as expired';

    public function handle(ExpireSubscriptionsAction $expireSubscriptions): int
    {
        $asOf = $this->option('as-of');
        $chunk = $this->option('chunk');

        $data = ExpireSubscriptionsData::fromCommand(
            is_string($asOf) ? $asOf : null,
            is_string($chunk) ? $chunk : null,
        );

        $expired = $expireSubscriptions($data);

        $this->info(sprintf(
            'subscriptions:expire marked %d subscription(s) expired as of %s.',
            $expired,
            $data->asOf->toDateString(),
        ));

        return self::SUCCESS;
    }
}
