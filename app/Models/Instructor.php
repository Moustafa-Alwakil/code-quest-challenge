<?php

declare(strict_types=1);

namespace App\Models;

use App\Builders\InstructorQueryBuilder;
use App\Enums\InstructorStatus;
use Carbon\CarbonImmutable;
use Database\Factories\InstructorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An instructor earns a share of recognized revenue and is paid out (F05-F07).
 *
 * Instructors are not users: there is no instructor portal (PLAN section 20).
 *
 * @property int              $id
 * @property string           $name
 * @property string           $email
 * @property string           $payout_account_ref
 * @property InstructorStatus $status
 * @property CarbonImmutable  $created_at
 * @property CarbonImmutable  $updated_at
 */
final class Instructor extends Model
{
    /** @use HasFactory<InstructorFactory> */
    use HasFactory;

    /** Scopes live on the builder, not on the model. */
    protected static string $builder = InstructorQueryBuilder::class;

    protected $fillable = [
        'name',
        'email',
        'payout_account_ref',
        'status',
    ];

    protected $attributes = [
        'status' => InstructorStatus::ACTIVE,
    ];

    /**
     * @return HasMany<Course, $this>
     */
    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InstructorStatus::class,
        ];
    }
}
