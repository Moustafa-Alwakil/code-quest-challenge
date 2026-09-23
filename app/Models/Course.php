<?php

declare(strict_types=1);

namespace App\Models;

use App\Builders\CourseBuilder;
use Database\Factories\CourseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A course belongs to exactly one instructor, which is what makes engagement
 * attributable to an instructor at recognition time (D-2).
 */
final class Course extends Model
{
    /** @use HasFactory<CourseFactory> */
    use HasFactory;

    /** Scopes live on the builder, not on the model. */
    protected static string $builder = CourseBuilder::class;

    protected $fillable = [
        'instructor_id',
        'title',
        'slug',
        'published_at',
    ];

    /**
     * @return BelongsTo<Instructor, $this>
     */
    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    /**
     * @return HasMany<Enrolment, $this>
     */
    public function enrolments(): HasMany
    {
        return $this->hasMany(Enrolment::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }
}
