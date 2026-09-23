<?php

declare(strict_types=1);

namespace App\Models;

use App\Builders\PlanBuilder;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A subscription plan: term length and price.
 *
 * `price_minor` is signed BIGINT piastres cast to int — the accrual schedule
 * splits it across `interval_months` periods with the largest-remainder
 * method, so the periods sum to the price exactly (D-1, D-5).
 */
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    /** Scopes live on the builder, not on the model. */
    protected static string $builder = PlanBuilder::class;

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
