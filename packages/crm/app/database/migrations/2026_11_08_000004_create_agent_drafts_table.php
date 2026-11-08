<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror of `packages/crm/db/migrations/0033_agent_calibration.sql` for the
 * SQLite test schema. Production applies the SQL migration directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('artist_settings', 'reply_mode')) {
            Schema::table('artist_settings', function (Blueprint $table) {
                $table->string('reply_mode')->default('draft');
            });
        }

        if (Schema::hasTable('agent_drafts')) {
            return;
        }

        Schema::create('agent_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('artist_id');
            $table->uuid('lead_id')->nullable();
            $table->uuid('project_id')->nullable();
            $table->text('chat_id');
            $table->uuid('turn_id');
            $table->text('lead_text');
            $table->timestamp('lead_msg_at')->nullable();
            $table->string('intent')->nullable();
            $table->decimal('intent_confidence', 3, 2)->nullable();
            $table->json('facts')->nullable();
            $table->text('draft_reply');
            $table->json('claims')->nullable();
            $table->json('validation')->nullable();
            $table->json('simulated_actions')->nullable();
            $table->string('model')->nullable();
            $table->string('prompt_version')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->string('status')->default('pending');
            $table->text('artist_reply')->nullable();
            $table->timestamp('artist_replied_at')->nullable();
            $table->integer('response_seconds')->nullable();
            $table->json('artist_claims')->nullable();
            $table->json('artist_validation')->nullable();
            $table->json('comparison')->nullable();
            $table->timestamp('compared_at')->nullable();
            $table->timestamps();

            $table->unique(['artist_id', 'turn_id']);
            $table->index(['artist_id', 'chat_id']);
            $table->index(['artist_id', 'status']);
            $table->index(['lead_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_drafts');

        if (Schema::hasColumn('artist_settings', 'reply_mode')) {
            Schema::table('artist_settings', function (Blueprint $table) {
                $table->dropColumn('reply_mode');
            });
        }
    }
};
