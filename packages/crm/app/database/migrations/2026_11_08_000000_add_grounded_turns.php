<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror of `packages/crm/db/migrations/0025_grounded_turns.sql` for the SQLite
 * test schema. Production applies the SQL migration directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('artist_settings', 'grounding_mode')) {
            Schema::table('artist_settings', function (Blueprint $table) {
                $table->string('grounding_mode')->default('off');
            });
        }

        if (Schema::hasTable('agent_turn_log')) {
            return;
        }

        Schema::create('agent_turn_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('turn_id');
            $table->uuid('artist_id')->nullable();
            $table->uuid('lead_id')->nullable();
            $table->uuid('project_id')->nullable();
            $table->string('chat_id')->nullable();
            $table->string('mode')->default('shadow');
            $table->string('intent')->nullable();
            $table->decimal('intent_confidence', 3, 2)->nullable();
            $table->json('facts')->nullable();
            $table->text('reply')->nullable();
            $table->json('claims')->nullable();
            $table->json('violations')->nullable();
            $table->string('resolution')->nullable();
            $table->string('model')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->dateTime('created_at')->nullable();

            $table->index(['artist_id', 'created_at']);
            $table->index('turn_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_turn_log');

        if (Schema::hasColumn('artist_settings', 'grounding_mode')) {
            Schema::table('artist_settings', function (Blueprint $table) {
                $table->dropColumn('grounding_mode');
            });
        }
    }
};
