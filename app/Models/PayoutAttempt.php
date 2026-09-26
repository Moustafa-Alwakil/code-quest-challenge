<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PayoutAttemptOperation;
use Carbon\CarbonImmutable;
use Database\Factories\PayoutAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One interaction with the provider, kept forever (F07).
 *
 * Append-only by convention, like `LedgerEntry`: an attempt is a record of
 * something that happened, and rewriting it would destroy the only evidence
 * that a timeout and its later confirmation were one transfer rather than two.
 *
 * @property int                    $id
 * @property int                    $payout_item_id
 * @property int                    $attempt_no
 * @property PayoutAttemptOperation $operation
 * @property string                 $request
 * @property string                 $response
 * @property string                 $outcome
 * @property int                    $duration_ms
 * @property CarbonImmutable        $created_at
 */
final class PayoutAttempt extends Model
{
    /** @use HasFactory<PayoutAttemptFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'payout_item_id',
        'attempt_no',
        'operation',
        'request',
        'response',
        'outcome',
        'duration_ms',
    ];

    /**
     * @return BelongsTo<PayoutItem, $this>
     */
    public function payoutItem(): BelongsTo
    {
        return $this->belongsTo(PayoutItem::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempt_no' => 'integer',
            'operation' => PayoutAttemptOperation::class,
            'duration_ms' => 'integer',
        ];
    }
}
