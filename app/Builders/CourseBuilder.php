<?php

declare(strict_types=1);

namespace App\Builders;

use App\Models\Course;
use App\Models\Instructor;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<Course>
 */
final class CourseBuilder extends Builder
{
    /**
     * Courses visible to students: published, and not scheduled for the future.
     */
    public function published(): self
    {
        return $this->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /**
     * @param Instructor|int $instructor
     */
    public function forInstructor(mixed $instructor): self
    {
        return $this->where('instructor_id', $instructor);
    }
}
