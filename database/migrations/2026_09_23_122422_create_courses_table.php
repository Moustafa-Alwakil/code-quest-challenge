<?php

declare(strict_types=1);

use App\Models\Instructor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table): void {
            $table->id();

            /**
             * restrictOnDelete, not cascade: an instructor with courses has earned
             * money against them, and deleting the courses would orphan the ledger.
             */
            $table->foreignIdFor(Instructor::class)->constrained()->restrictOnDelete();

            $table->string('title');
            $table->string('slug')->unique();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
