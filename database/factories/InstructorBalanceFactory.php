<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Instructor;
use App\Models\InstructorBalance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InstructorBalance>
 *
 * Defaults to an all-zero row, which is the only state that agrees with an
 * empty ledger. Any test that wants a non-zero balance should post to the
 * ledger for it — a hand-set snapshot is by definition a corrupt one, and
 * `ledger:verify` will say so.
 */
final class InstructorBalanceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'instructor_id' => Instructor::factory(),
            'currency' => 'EGP',
            'earned_minor' => 0,
            'clawed_back_minor' => 0,
            'held_minor' => 0,
            'available_minor' => 0,
            'reserved_minor' => 0,
            'paid_minor' => 0,
            'last_ledger_entry_id' => 0,
        ];
    }
}
