<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use App\Models\Plan;
use App\Support\Subscriptions\PlanTerms;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The catalog side of a purchase: what a plan costs and how long it runs.
 *
 * Read-only, and one method, because a plan's price is snapshotted onto the
 * subscription at purchase (F04) and nothing later may reach back for it.
 */
interface PlanServiceContract
{
    /**
     * @throws ModelNotFoundException<Plan> when the plan does not exist — a payment for a plan that
     *                                      is not on file is not a fact this system can record
     */
    public function termsFor(int $planId): PlanTerms;
}
