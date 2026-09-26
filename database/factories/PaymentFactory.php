<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Payment;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 *
 * `external_ref` is random per row because it is a UNIQUE index: a factory that
 * repeated it would fail on the second call, which is the constraint doing its
 * job. A payment made here has no ledger entry behind it — see
 * SubscriptionFactory's note.
 */
final class PaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'external_ref' => 'ch_'.Str::lower(Str::random(24)),
            'amount_minor' => 30_000,
            'currency' => 'EGP',
            'captured_at' => CarbonImmutable::now(),
        ];
    }
}
