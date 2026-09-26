<?php

declare(strict_types=1);

use App\Models\Instructor;
use App\Models\PayoutRun;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One instructor's payment inside one run (F06, executed by F07).
     *
     * Two unique keys, for two different guarantees:
     *
     * - `(payout_run_id, instructor_id)` — at most one item per instructor per
     *   run, however many times the command is invoked (PLAN §9 row 6).
     * - `idempotency_key` — what the provider dedups on, so an at-least-once
     *   job delivery becomes an effectively-once transfer (row 8).
     *
     * Neither replaces the other, and neither replaces reservation: the unique
     * pair alone would let a *second run key* pay the same balance again, and
     * reservation alone would let two invocations of one key race.
     *
     * Items are born `reserved` — creation and the reservation posting share a
     * transaction, so there is no `pending` state a crash could strand.
     */
    public function up(): void
    {
        Schema::create('payout_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(PayoutRun::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Instructor::class)->constrained()->restrictOnDelete();

            $table->bigInteger('amount_minor');
            $table->char('currency', 3);

            /** reserved | submitted | succeeded | failed | unknown | needs_review. */
            $table->string('status', 16);

            /** The provider's dedup key. NOT NULL, or the unique index is disabled (R4). */
            $table->char('idempotency_key', 36);

            $table->string('provider_reference', 64)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);

            /** When reconciliation should next ask the provider what happened (F08). */
            $table->timestamp('next_check_at')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->string('last_error', 255)->nullable();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['payout_run_id', 'instructor_id'], 'payout_items_run_instructor_unique');
            $table->unique('idempotency_key', 'payout_items_idempotency_unique');

            /** The reconciliation sweep (F08). */
            $table->index(['status', 'next_check_at'], 'payout_items_reconcile_index');

            /** An instructor's payout history, for the admin screen (F10). */
            $table->index(['instructor_id', 'created_at'], 'payout_items_instructor_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_items');
    }
};
