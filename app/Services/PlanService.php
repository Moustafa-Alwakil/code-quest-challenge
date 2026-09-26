<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Plan;
use App\Support\Money;
use App\Support\Subscriptions\PlanTerms;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The `plans` aggregate: what a plan costs and how long it runs (F02, F04).
 *
 * Small on purpose. The subscribe use case has to compare a captured payment
 * against the plan's price, and an Action may not query — so the read lives
 * here, with the aggregate it belongs to, rather than being smuggled into
 * SubscriptionService because that was closer to hand.
 */
final class PlanService
{
    /**
     * @throws ModelNotFoundException<Plan> when the plan does not exist — a payment for a plan that
     *                                      is not on file is not a fact this system can record
     */
    public function termsFor(int $planId): PlanTerms
    {
        $plan = Plan::query()->findOrFail($planId, ['id', 'interval_months', 'price_minor', 'currency']);

        return new PlanTerms(
            $plan->id,
            $plan->interval_months,
            Money::of($plan->price_minor, $plan->currency),
        );
    }
}
