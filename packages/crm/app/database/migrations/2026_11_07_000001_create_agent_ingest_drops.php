<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror of `packages/crm/db/migrations/0024_ingest_drops.sql` for the SQLite
 * test schema. Production applies the SQL migration directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agent_ingest_drops')) {
            return;
        }

        Schema::create('agent_ingest_drops', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('artist_id')->nullable();
            $table->string('session_slug')->nullable();
            $table->string('phone_e164')->nullable();
            $table->string('chat_id')->nullable();
            $table->string('reason');
            $table->json('payload')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('notified_at')->nullable();

            $table->index(['artist_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_ingest_drops');
    }
};
