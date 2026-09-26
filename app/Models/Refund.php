<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RefundType;
use Carbon\CarbonImmutable;
use Database\Factories\RefundFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A refund the gateway executed, and this system recorded once (F09, R25).
 *
 * One per subscription, enforced by a unique index rather than by a check: a
 * second refund attempt is a no-op, not an error, because the caller replaying
 * a webhook has done nothing wrong.
 *
 * @property int             $id
 * @property int             $subscription_id
 * @property int             $payment_id
 * @property RefundType      $type
 * @property int             $amount_minor
 * @property string          $currency
 * @property CarbonImmutable $effective_at
 * @property string          $external_ref
 * @property string|null     $reason
 * @property CarbonImmutable $created_at
 */
final class Refund extends Model
{
    /** @use HasFactory<RefundFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'subscription_id',
        'payment_id',
        'type',
        'amount_minor',
        'currency',
        'effective_at',
        'external_ref',
        'reason',
    ];

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => RefundType::class,
            'amount_minor' => 'integer',
            'effective_at' => 'immutable_date',
        ];
    }
}
