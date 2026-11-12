<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror of `packages/crm/db/migrations/0043_observe_review_queue.sql` for the
 * SQLite test schema. Production applies the SQL migration directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('observed_writes')) {
            return;
        }

        Schema::create('observed_writes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('artist_id');
            $table->uuid('lead_id')->nullable();
            $table->uuid('project_id')->nullable();
            $table->text('chat_id')->nullable();
            $table->uuid('observed_turn_id')->nullable();
            $table->string('kind');
            $table->string('status')->default('pending');
            $table->string('dedupe_key');
            $table->decimal('confidence', 3, 2)->nullable();
            $table->json('payload')->nullable();
            $table->json('evidence')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['artist_id', 'dedupe_key']);
            $table->index(['artist_id', 'status', 'created_at']);
            $table->index(['lead_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('observed_writes');
    }
};
