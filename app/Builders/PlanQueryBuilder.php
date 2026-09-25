<?php

declare(strict_types=1);

namespace App\Builders;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<Plan>
 */
final class PlanQueryBuilder extends Builder
{
    /**
     * Plans a student may still subscribe to.
     *
     * Distinct from InstructorBuilder::active(), which reads a status enum.
     * The two mean different things, which is why they are separate classes
     * rather than one shared builder.
     */
    public function active(): self
    {
        return $this->where('is_active', true);
    }
}
