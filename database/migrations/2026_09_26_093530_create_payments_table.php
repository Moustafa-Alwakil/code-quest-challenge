<?php

declare(strict_types=1);

use App\Models\Subscription;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A captured payment, recorded once (F04, R25).
     *
     * This system does not take card payments: it records the gateway's fact.
     * `external_ref` is that fact's identity, and the UNIQUE index on it *is*
     * the idempotency mechanism — recording the same payment twice is a no-op,
     * and two concurrent attempts are serialized by this index rather than by a
     * lock. It is NOT NULL for the reason R4 exists: MySQL treats NULLs as
     * distinct, so one nullable column would silently disable the guarantee.
     *
     * UNIQUE `subscription_id` keeps the up-front payment 1:1 with the term it
     * paid for; instalments would be a different data model, not a second row.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(Subscription::class)->constrained()->cascadeOnDelete();

            /** The gateway's charge id. */
            $table->string('external_ref', 64);

            $table->bigInteger('amount_minor');
            $table->char('currency', 3);

            /**
             * When the gateway captured the money — the term starts here, not
             * at `created_at`, so a late-arriving record cannot shorten the
             * student's term.
             */
            $table->timestamp('captured_at');

            /** `created_at` is when this system learned of the payment. */
            $table->timestamps();

            $table->unique('subscription_id', 'payments_subscription_unique');
            $table->unique('external_ref', 'payments_external_ref_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
