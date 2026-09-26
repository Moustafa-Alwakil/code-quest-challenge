<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How much of a term is being given back (F09).
 *
 * The distinction is the whole feature. Under accrual (D-1) a pro-rata refund
 * of unused time is exactly the periods nobody has earned yet, so it costs no
 * instructor anything — the normal path never touches an earning. A full refund
 * reaches back past that line and has to claw earnings back, which is the only
 * reason clawbacks exist at all.
 *
 * Arbitrary partial amounts are out of scope: they would have no principled
 * mapping onto periods, and inventing one is how a refund starts costing an
 * instructor money nobody decided to take.
 */
enum RefundType: string
{
    case PRORATA = 'prorata';

    case FULL = 'full';

    /**
     * Whether this refund can reach money an instructor has already earned.
     */
    public function clawsBack(): bool
    {
        return $this === self::FULL;
    }
}
