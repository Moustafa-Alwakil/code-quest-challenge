<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A captured payment, recorded once and keyed by the gateway's reference (F04).
 *
 * `external_ref` is the idempotency key, enforced by a UNIQUE index rather than
 * by anything in PHP: recording the same payment twice writes nothing, and two
 * concurrent attempts are serialized by that index (R25, D-10).
 *
 * `captured_at` is the gateway's timestamp and the term's anchor; `created_at`
 * is when this system learned of it. The two are deliberately different columns.
 *
 * @property int             $id
 * @property int             $subscription_id
 * @property string          $external_ref
 * @property int             $amount_minor
 * @property string          $currency
 * @property CarbonImmutable $captured_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'subscription_id',
        'external_ref',
        'amount_minor',
        'currency',
        'captured_at',
    ];

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'captured_at' => 'immutable_datetime',
        ];
    }
}
