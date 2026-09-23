<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InstructorStatus;
use App\Models\Instructor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Instructor>
 */
final class InstructorFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'payout_account_ref' => 'acct_'.Str::lower(Str::random(16)),
            'status' => InstructorStatus::Active,
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => InstructorStatus::Suspended,
        ]);
    }
}
