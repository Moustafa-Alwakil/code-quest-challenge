<?php

declare(strict_types=1);

namespace App\Support\Ledger;

use InvalidArgumentException;

/**
 * The signed change a posting makes to one instructor's balance snapshot (F03).
 *
 * The snapshot is a cache of the ledger, so every delta is supplied explicitly
 * by the action that knows what happened — a clawback splits between `held` and
 * `available` depending on allocation state, and only the caller knows that
 * split. `ledger:verify` is what catches an action that supplies the wrong one.
 *
 * The named constructors are the vocabulary: each is one movement in the money
 * lifecycle, so no caller ever hand-rolls a six-argument call.
 */
final readonly class BalanceDelta
{
    private function __construct(
        public int $instructorId,
        public int $earned = 0,
        public int $clawedBack = 0,
        public int $held = 0,
        public int $available = 0,
        public int $reserved = 0,
        public int $paid = 0,
    ) {
        if ($instructorId <= 0) {
            throw new InvalidArgumentException("A balance delta needs a positive instructor id, got {$instructorId}.");
        }
    }

    /**
     * A period was recognized: the instructor has earned the amount, and it
     * starts its life held (R2, D-6), so `available` does not move yet.
     */
    public static function recognized(int $instructorId, int $amountMinor): self
    {
        return new self($instructorId, earned: $amountMinor, held: $amountMinor);
    }

    /**
     * The hold expired: the same money becomes payable. No ledger entry
     * accompanies this — the hold is allocation state, not a ledger fact (R2).
     */
    public static function released(int $instructorId, int $amountMinor): self
    {
        return new self($instructorId, held: -$amountMinor, available: $amountMinor);
    }

    /**
     * A payout run reserved the money: it leaves `instructor_payable` for
     * `provider_in_transit` and can no longer be reserved twice (F06).
     */
    public static function reserved(int $instructorId, int $amountMinor): self
    {
        return new self($instructorId, available: -$amountMinor, reserved: $amountMinor);
    }

    /**
     * The provider confirmed the transfer: reserved money becomes paid (F07).
     */
    public static function settled(int $instructorId, int $amountMinor): self
    {
        return new self($instructorId, reserved: -$amountMinor, paid: $amountMinor);
    }

    /**
     * The transfer definitively failed: the reservation returns to the
     * instructor's available balance (F07, F08).
     */
    public static function reversed(int $instructorId, int $amountMinor): self
    {
        return new self($instructorId, reserved: -$amountMinor, available: $amountMinor);
    }

    /**
     * A full refund clawed earnings back. What is still held costs nothing to
     * reverse; the rest comes out of `available`, which may go negative and
     * carry forward (D-7).
     */
    public static function clawedBack(int $instructorId, int $fromHeldMinor, int $fromAvailableMinor): self
    {
        return new self(
            $instructorId,
            clawedBack: $fromHeldMinor + $fromAvailableMinor,
            held: -$fromHeldMinor,
            available: -$fromAvailableMinor,
        );
    }

    /**
     * Combines two movements for the same instructor into one row update, so a
     * posting that both earns and releases still touches the row once.
     */
    public function plus(self $other): self
    {
        if ($other->instructorId !== $this->instructorId) {
            throw new InvalidArgumentException("Cannot merge balance deltas for instructors {$this->instructorId} and {$other->instructorId}.");
        }

        return new self(
            $this->instructorId,
            earned: $this->earned + $other->earned,
            clawedBack: $this->clawedBack + $other->clawedBack,
            held: $this->held + $other->held,
            available: $this->available + $other->available,
            reserved: $this->reserved + $other->reserved,
            paid: $this->paid + $other->paid,
        );
    }
}
