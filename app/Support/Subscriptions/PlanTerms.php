<?php

declare(strict_types=1);

namespace App\Support\Subscriptions;

use App\Support\Money;

/**
 * What a plan costs and how long it runs, lifted out of the model (F04).
 *
 * The Action needs the plan's terms to validate the payment and to build the
 * schedule, but may not query — so PlanService reads the row and hands back
 * this, and no Eloquent model crosses into the use-case layer.
 */
final readonly class PlanTerms
{
    public function __construct(
        public int $planId,
        public int $intervalMonths,
        public Money $price,
    ) {}
}
