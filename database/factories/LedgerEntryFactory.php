<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LedgerAccountType;
use App\Enums\LedgerEntryType;
use App\Models\Instructor;
use App\Models\LedgerEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LedgerEntry>
 *
 * Single legs, for tests about the row itself (immutability, casts). A balanced
 * posting comes from LedgerService, never from this factory: a factory that can
 * write half a transaction is a factory that can prove the wrong thing.
 */
final class LedgerEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'transaction_uuid' => (string) Str::uuid(),
            'account_type' => LedgerAccountType::PLATFORM_CASH,
            'account_id' => 0,
            'amount_minor' => fake()->numberBetween(1, 100_000),
            'currency' => 'EGP',
            'entry_type' => LedgerEntryType::PAYMENT_RECEIVED,
            'reference_type' => 'payment',
            'reference_id' => fake()->numberBetween(1, 100_000),
        ];
    }

    public function forInstructor(Instructor $instructor, int $amountMinor): static
    {
        return $this->state(fn (array $attributes): array => [
            'account_type' => LedgerAccountType::INSTRUCTOR_PAYABLE,
            'account_id' => $instructor->id,
            'amount_minor' => $amountMinor,
        ]);
    }
}
