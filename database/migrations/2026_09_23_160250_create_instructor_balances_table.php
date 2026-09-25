<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A per-instructor snapshot for O(1) reads — a cache with a provable source
     * of truth. `ledger:verify` recomputes every column here from
     * `ledger_entries`, `currency` included (R21), and fails loudly when the two
     * disagree.
     *
     * `last_ledger_entry_id` is the single exemption: a debugging watermark the
     * verifier does not check and no code may branch on (R19).
     */
    public function up(): void
    {
        Schema::create('instructor_balances', function (Blueprint $table): void {
            $table->unsignedBigInteger('instructor_id')->primary();
            $table->char('currency', 3);

            $table->bigInteger('earned_minor')->default(0);
            $table->bigInteger('clawed_back_minor')->default(0);

            /** Recognized but still inside the hold window (R2, D-6). */
            $table->bigInteger('held_minor')->default(0);

            /** Signed, and allowed to go negative: a clawback beyond the balance carries forward (D-7). */
            $table->bigInteger('available_minor')->default(0);

            /** Sent to the provider, outcome not yet confirmed. */
            $table->bigInteger('reserved_minor')->default(0);
            $table->bigInteger('paid_minor')->default(0);

            /** Watermark of the last posting folded into this row; a debugging aid, not an invariant. */
            $table->unsignedBigInteger('last_ledger_entry_id')->default(0);

            /**
             * MySQL maintains this, not Eloquent: the row is only ever written
             * by atomic `UPDATE ... SET x = x + ?` increments, which never go
             * through a model. No created_at — the row is born on first posting.
             */
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->foreign('instructor_id')
                ->references('id')
                ->on('instructors')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_balances');
    }
};
