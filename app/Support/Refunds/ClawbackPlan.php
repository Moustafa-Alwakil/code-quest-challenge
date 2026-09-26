<?php

declare(strict_types=1);

namespace App\Support\Refunds;

/**
 * Taking back earnings a full refund has reached past (F09, D-6, D-7).
 *
 * **Exact reversal, no re-rounding.** Each clawback is the allocation's own
 * amount, never a recomputed share: recomputing would run the largest-remainder
 * split again over the same weights and could land a piastre somewhere else,
 * creating or destroying money that the ledger has already recorded.
 *
 * The split between `held` and `available` is the point of the hold (D-6).
 * Money still inside its hold window costs nothing to reverse — that is the
 * common case, and why the hold exists. Money already released comes out of
 * `available`, which may go negative and carry forward (D-7).
 */
final readonly class ClawbackPlan
{
    /**
     * @param array<int, int> $fromHeldByInstructor      instructor id => minor units still held
     * @param array<int, int> $fromAvailableByInstructor instructor id => minor units already released
     * @param list<int>       $allocationIds             every allocation being reversed
     */
    private function __construct(
        public array $fromHeldByInstructor,
        public array $fromAvailableByInstructor,
        public array $allocationIds,
        public int $instructorTotalMinor,
        public int $platformMinor,
    ) {}

    /**
     * @param list<AllocationLine> $allocations   every allocation of every recognized period
     * @param int                  $platformMinor the platform's own recognized share of those periods
     */
    public static function forAllocations(array $allocations, int $platformMinor): self
    {
        $held = [];
        $available = [];
        $ids = [];
        $total = 0;

        foreach ($allocations as $allocation) {
            $ids[] = $allocation->id;
            $total += $allocation->amountMinor;

            if ($allocation->isReleased) {
                $available[$allocation->instructorId] = ($available[$allocation->instructorId] ?? 0) + $allocation->amountMinor;

                continue;
            }

            $held[$allocation->instructorId] = ($held[$allocation->instructorId] ?? 0) + $allocation->amountMinor;
        }

        /** Ascending instructor id, the lock order used everywhere else. */
        ksort($held);
        ksort($available);

        return new self($held, $available, $ids, $total, $platformMinor);
    }

    /**
     * Every instructor this clawback touches, ascending — the order their
     * snapshot rows must be locked in to avoid deadlocking a concurrent
     * recognition or payout.
     *
     * @return list<int>
     */
    public function instructorIds(): array
    {
        $ids = array_unique([
            ...array_keys($this->fromHeldByInstructor),
            ...array_keys($this->fromAvailableByInstructor),
        ]);

        sort($ids);

        return $ids;
    }

    public function amountFor(int $instructorId): int
    {
        return ($this->fromHeldByInstructor[$instructorId] ?? 0)
            + ($this->fromAvailableByInstructor[$instructorId] ?? 0);
    }

    /**
     * The whole recognized amount being reversed: what instructors earned plus
     * what the platform kept.
     */
    public function totalMinor(): int
    {
        return $this->instructorTotalMinor + $this->platformMinor;
    }

    public function isEmpty(): bool
    {
        return $this->totalMinor() === 0;
    }
}
