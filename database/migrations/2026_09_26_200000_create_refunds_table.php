<?php

declare(strict_types=1);

use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A refund the gateway has already executed (F09, R25).
     *
     * Like a payment, this records a fact rather than attempting one: the money
     * has gone back to the student outside this system, and F09's job is to
     * apply the consequences to the ledger exactly once.
     *
     * Two unique keys, both load-bearing. `subscription_id` is the business
     * rule — one refund per term, so a second call is a no-op rather than a
     * second cancellation of periods that are already cancelled.
     * `external_ref` is the gateway's own id, which makes a replayed webhook or
     * a re-run command harmless.
     */
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(Subscription::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Payment::class)->constrained()->restrictOnDelete();

            /** prorata | full. Arbitrary partial amounts are out of scope. */
            $table->string('type', 16);

            $table->bigInteger('amount_minor');
            $table->char('currency', 3);

            /** The date the student stops having access; what pro-rata is measured from. */
            $table->date('effective_at');

            /** The gateway's refund id. NOT NULL, or the unique index is disabled (R4). */
            $table->string('external_ref', 64);

            $table->string('reason', 255)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->unique('subscription_id', 'refunds_subscription_unique');
            $table->unique('external_ref', 'refunds_external_ref_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
