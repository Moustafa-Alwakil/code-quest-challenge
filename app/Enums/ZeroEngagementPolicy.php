<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What happens to a period's instructor pool when the subscription generated no
 * engagement at all (D-3).
 *
 * The dial exists so the decision is visible rather than buried in the
 * recognizer: the weight denominator is zero, so there is no defensible
 * proportion, and any alternative invents a beneficiary out of nothing.
 *
 * Only PLATFORM_RETAINS is implemented. SPLIT_EQUALLY — an equal share among
 * the instructors whose courses the student is enrolled in — is equally
 * arguable and is documented as a one-line policy swap, which is exactly why it
 * is a case here and a rejection at the boundary rather than a silent fallback.
 */
enum ZeroEngagementPolicy: string
{
    case PLATFORM_RETAINS = 'platform_retains';

    case SPLIT_EQUALLY = 'split_equally';

    /**
     * Whether the recognizer can actually act on this policy today.
     */
    public function isImplemented(): bool
    {
        return match ($this) {
            self::PLATFORM_RETAINS => true,
            self::SPLIT_EQUALLY => false,
        };
    }
}
