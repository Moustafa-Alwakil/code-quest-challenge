<?php

declare(strict_types=1);

namespace App\Models;

use App\Builders\InstructorQueryBuilder;
use App\Enums\InstructorStatus;
use App\Enums\LedgerAccountType;
use Carbon\CarbonImmutable;
use Database\Factories\InstructorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
     * The balance snapshot: one row, created by this instructor's first
     * posting, so an instructor the ledger has never mentioned has none (F03).
     *
     * @return HasOne<InstructorBalance, $this>
     */
    public function balance(): HasOne
    {
        return $this->hasOne(InstructorBalance::class);
    }

    /**
     * @return HasMany<PayoutItem, $this>
     */
    public function payoutItems(): HasMany
    {
        return $this->hasMany(PayoutItem::class);
    }

    /**
     * @return HasMany<EarningAllocation, $this>
     */
    public function earningAllocations(): HasMany
    {
        return $this->hasMany(EarningAllocation::class);
    }

    /**
     * This instructor's own ledger entries: what they are owed, and what is in
     * transit to them (R5).
     *
     * Keyed on `account_id` rather than a foreign key, because `ledger_entries`
     * is shared by five account types and only two of them are keyed by
     * instructor. The `whereIn` is what stops this relation returning a
     * subscription's deferred revenue because the ids happened to collide.
     *
     * @return HasMany<LedgerEntry, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'account_id')
            ->whereIn('account_type', LedgerAccountType::instructorKeyed());
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
