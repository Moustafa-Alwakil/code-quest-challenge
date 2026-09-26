<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One batch of payouts, identified by a key the caller chooses (F06).
     *
     * UNIQUE `run_key` is idempotency mechanism #5 (PLAN §9): re-running
     * `payouts:run` for the same period finds the existing run rather than
     * opening a second one, and two processes racing to create it resolve on
     * the index rather than on a lock.
     *
     * The key is the caller's, not a timestamp, precisely so that a re-trigger
     * can be *deliberate*: `payout:2026-09` twice is a resume, and a new key is
     * a new run over whatever has since become available.
     */
    public function up(): void
    {
        Schema::create('payout_runs', function (Blueprint $table): void {
            $table->id();

            /** Defaults to `payout:YYYY-MM`; anything the operator passes is honoured. */
            $table->string('run_key', 64);

            $table->date('scheduled_for');

            /** open | dispatched | completed | completed_with_pending. */
            $table->string('status', 24);

            /** Totals of what this run reserved, filled in as items are created. */
            $table->unsignedInteger('item_count')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->char('currency', 3);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            /**
             * MySQL maintains these: a run is created by `insertOrIgnore` and
             * advanced by conditional `UPDATE`s, neither of which fires model
             * events.
             */
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique('run_key', 'payout_runs_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_runs');
    }
};
