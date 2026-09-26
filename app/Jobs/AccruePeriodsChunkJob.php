<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Accrual\RecognizeAccrualPeriodAction;
use App\DTOs\Accrual\RecognizePeriodData;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Recognizes one keyset chunk of due accrual periods (F05).
 *
 * **Scalars only in the constructor (R15).** A serialized DTO goes stale when a
 * deploy lands mid-queue, and a model would be re-fetched at a version nobody
 * chose. The DTO is rebuilt in `handle()` from what the dispatch decided.
 *
 * That includes the clock: `recognizedAt` travels as a string rather than being
 * re-read on the worker (R27), so a job retried an hour later recognizes the
 * window its dispatch intended, at the share and hold that run was configured
 * with — not at whatever `config/revenue.php` says when the worker picks it up.
 *
 * **No `failed()` handler, deliberately.** There is no terminal review state to
 * move a period to: the compare-and-set and every write share one transaction
 * (R3), so a failed chunk leaves its periods exactly as it found them —
 * `scheduled` — and tomorrow's run picks them up. Inventing a `needs_review`
 * status here would strand money that recovers on its own.
 *
 * One period failing does not take its chunk's siblings with it: each
 * recognition is its own transaction, and the ones that committed stay
 * committed. The retry re-attempts all of them, and the CAS makes the
 * already-recognized ones no-ops.
 */
final class AccruePeriodsChunkJob implements ShouldQueue
{
    use Batchable;
    use Queueable;

    /**
     * @param list<int> $periodIds ascending, from the sweep's keyset page
     */
    public function __construct(
        private array $periodIds,
        private int $instructorShareBps,
        private int $holdDays,
        private string $currency,
        private string $zeroEngagementPolicy,
        private string $recognizedAt,
    ) {}

    public function handle(RecognizeAccrualPeriodAction $recognizeAccrualPeriod): void
    {
        foreach ($this->periodIds as $periodId) {
            if ($this->batch()?->cancelled() === true) {
                return;
            }

            $recognizeAccrualPeriod(RecognizePeriodData::fromJob(
                $periodId,
                $this->instructorShareBps,
                $this->holdDays,
                $this->currency,
                $this->zeroEngagementPolicy,
                $this->recognizedAt,
            ));
        }
    }
}
