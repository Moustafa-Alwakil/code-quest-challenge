<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The append-only, double-entry ledger: the single source of truth for
     * every piastre (D-9).
     *
     * Nothing ever updates or deletes a row here — a correction is a new entry
     * with its own entry type. Enum columns are string + a PHP cast, not MySQL
     * ENUM, so adding a case is a code change rather than an ALTER TABLE.
     */
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->char('transaction_uuid', 36);
            $table->string('account_type', 24);
            $table->unsignedBigInteger('account_id');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('entry_type', 32);
            $table->string('reference_type', 64);
            $table->unsignedBigInteger('reference_id');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                columns: [
                    'entry_type',
                    'reference_type',
                    'reference_id',
                    'account_type',
                    'account_id',
                ],
                name: 'ledger_entries_idempotency_unique',
            );

            $table->index(['account_type', 'account_id', 'id'], 'ledger_entries_account_index');

            $table->index('transaction_uuid', 'ledger_entries_transaction_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
