<?php

declare(strict_types=1);

use App\Models\PayoutItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only audit of every interaction with the provider (F07, PLAN §5.5).
     *
     * This table exists so the video can show, on screen, that a timeout and
     * its later confirmation were two *interactions* with **one** transfer —
     * not two transfers. Without it, "we retried safely" is a claim; with it,
     * it is a row count.
     *
     * Never updated and never deleted, like the ledger. A late or duplicate
     * provider response that changes no money still lands here, because "we
     * heard this twice and ignored the second" is exactly what an auditor
     * needs to see.
     */
    public function up(): void
    {
        Schema::create('payout_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(PayoutItem::class)->constrained()->cascadeOnDelete();

            /** 1-based, in the order the item was worked on. */
            $table->unsignedSmallInteger('attempt_no');

            /** transfer | status — which call was made. */
            $table->string('operation', 16);

            /** What we asked, and what came back. Summaries, not raw payloads. */
            $table->string('request', 255);
            $table->string('response', 255);

            /** The status the provider reported, or the exception that stood in for one. */
            $table->string('outcome', 32);

            $table->unsignedInteger('duration_ms');

            $table->timestamp('created_at')->useCurrent();

            /** An item's history, in order. */
            $table->index(['payout_item_id', 'attempt_no'], 'payout_attempts_item_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_attempts');
    }
};
