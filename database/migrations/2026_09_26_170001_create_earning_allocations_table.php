<?php

declare(strict_types=1);

use App\Models\AccrualPeriod;
use App\Models\Instructor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What one instructor earned from one recognized period, and when that
     * money becomes payable (D-2, D-6, F05).
     *
     * The hold lives here rather than on the ledger (R2). The money is owed
     * from the moment of recognition — the ledger says so — and this row only
     * decides *when it becomes payable*. A second pair of ledger entries per
     * allocation would add tens of millions of rows carrying information
     * `released_at` already carries.
     *
     * `ledger:verify` recomputes an instructor's `held_minor` from the rows
     * here with neither `released_at` nor `clawed_back_at` set, so a wrong
     * value in this table is a red verification run, not silent drift.
     */
    public function up(): void
    {
        Schema::create('earning_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(AccrualPeriod::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Instructor::class)->constrained()->restrictOnDelete();

            /** The engagement units this share was computed from, kept for the audit trail. */
            $table->unsignedInteger('weight_units');

            $table->bigInteger('amount_minor');
            $table->char('currency', 3);

            /** `period_end + hold_days`: before this instant the money is held, not available. */
            $table->timestamp('available_at');

            /** Set by the maturation sweep; a released row no longer matches it. */
            $table->timestamp('released_at')->nullable();

            /** Set by a full refund (F09). A clawback of held money costs the instructor nothing. */
            $table->timestamp('clawed_back_at')->nullable();

            /** MySQL maintains it: rows arrive by `insertOrIgnore`, which fires no model events. */
            $table->timestamp('created_at')->useCurrent();

            /** One allocation per instructor per period — recognition replayed writes nothing. */
            $table->unique(['accrual_period_id', 'instructor_id'], 'earning_allocations_period_instructor_unique');

            /** The maturation sweep: `released_at IS NULL AND available_at <= ?`. */
            $table->index(['released_at', 'available_at'], 'earning_allocations_maturation_index');

            /** Per-instructor held recomputation, and F09's clawback. */
            $table->index('instructor_id', 'earning_allocations_instructor_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('earning_allocations');
    }
};
