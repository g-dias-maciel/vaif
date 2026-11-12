<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror of `packages/crm/db/migrations/0042_observe_mode.sql` for the SQLite
 * test schema. Production applies the SQL migration directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('observed_turns')) {
            Schema::create('observed_turns', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('artist_id');
                $table->uuid('lead_id')->nullable();
                $table->uuid('project_id')->nullable();
                $table->text('chat_id');
                $table->string('direction');
                $table->text('wa_message_id')->nullable();
                $table->dateTime('occurred_at');
                $table->text('text')->default('');
                $table->string('text_hash');
                $table->string('intent')->nullable();
                $table->json('entities')->nullable();
                $table->json('facts')->nullable();
                $table->string('model')->nullable();
                $table->string('status')->default('observed');
                $table->timestamps();

                $table->unique(['artist_id', 'chat_id', 'wa_message_id']);
                $table->unique(['artist_id', 'chat_id', 'direction', 'occurred_at']);
                $table->index(['artist_id', 'lead_id', 'occurred_at']);
                $table->index(['artist_id', 'chat_id', 'occurred_at']);
            });
        }

        if (! Schema::hasTable('lead_summaries')) {
            Schema::create('lead_summaries', function (Blueprint $table) {
                $table->uuid('lead_id')->primary();
                $table->text('body');
                $table->integer('message_count')->default(0);
                $table->string('model')->nullable();
                $table->dateTime('generated_at');
                $table->timestamps();

                $table->foreign('lead_id')->references('id')->on('leads')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_summaries');
        Schema::dropIfExists('observed_turns');
    }
};
