<?php

declare(strict_types=1);

namespace App\Models;

use App\Builders\PlanQueryBuilder;
use Carbon\CarbonImmutable;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A subscription plan: term length and price.
 *
 * `price_minor` is signed BIGINT piastres cast to int — the accrual schedule
 * splits it across `interval_months` periods with the largest-remainder
 * method, so the periods sum to the price exactly (D-1, D-5).
 *
 * @property int             $id
 * @property string          $key
 * @property string          $name
 * @property int             $interval_months
 * @property int             $price_minor
 * @property string          $currency
 * @property bool            $is_active
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    /** Scopes live on the builder, not on the model. */
    protected static string $builder = PlanQueryBuilder::class;

    protected $fillable = [
        'key',
        'name',
        'interval_months',
        'price_minor',
        'currency',
        'is_active',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'interval_months' => 'int',
            'price_minor' => 'int',
            'is_active' => 'bool',
        ];
    }
}
