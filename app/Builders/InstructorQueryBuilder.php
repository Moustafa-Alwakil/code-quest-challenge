<?php

declare(strict_types=1);

namespace App\Builders;

use App\Enums\InstructorStatus;
use App\Models\Instructor;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<Instructor>
 */
final class InstructorQueryBuilder extends Builder
{
    /**
     * Instructors currently teaching.
     *
     * Not a payout filter: a suspended instructor still earned what they
     * earned, and their balance still pays out (F02 rules).
     */
    public function active(): self
    {
        return $this->where('status', InstructorStatus::ACTIVE);
    }
}
