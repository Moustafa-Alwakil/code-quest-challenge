<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The mock provider's own database (F07, R8).
     *
     * Persistent, not in memory, and that is the whole point: "discover the
     * real result later" has to work from a *different worker process* than the
     * one that made the call. An in-memory mock cannot model a timeout whose
     * transfer actually succeeded, which is the failure this system is built
     * around (D-8).
     *
     * UNIQUE `idempotency_key` is the provider-side dedup our retries rely on.
     * It belongs here rather than in application code deliberately: it models
     * what a real provider guarantees, so the tests exercise the same contract
     * production would.
     */
    public function up(): void
    {
        Schema::create('mock_provider_transfers', function (Blueprint $table): void {
            $table->id();

            /** What the caller keyed the transfer by. Asking twice returns the first answer. */
            $table->char('idempotency_key', 36);

            $table->string('account_ref', 64);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);

            /** succeeded | failed | pending. */
            $table->string('status', 16);

            $table->string('provider_reference', 64)->nullable();
            $table->string('failure_code', 64)->nullable();

            /**
             * Video scenario 5: a transfer recorded `pending` that flips to
             * succeeded once it has been asked about this many times.
             */
            $table->unsignedSmallInteger('confirm_after_checks')->default(0);
            $table->unsignedSmallInteger('status_checks')->default(0);

            /**
             * How many times the provider actually *moved money* for this key,
             * as opposed to how many times it was asked to. The test suite's
             * source of truth for "the money moved once".
             */
            $table->unsignedSmallInteger('transfer_executions')->default(0);
            $table->unsignedSmallInteger('transfer_calls')->default(0);

            $table->timestamp('processed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique('idempotency_key', 'mock_provider_transfers_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mock_provider_transfers');
    }
};
