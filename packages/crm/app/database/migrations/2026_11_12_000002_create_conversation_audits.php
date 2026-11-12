<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror of `packages/crm/db/migrations/0044_conversation_audits.sql` for the
 * SQLite test schema. Production applies the SQL migration directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('conversation_audits')) {
            return;
        }

        Schema::create('conversation_audits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('artist_id');
            $table->uuid('lead_id')->nullable();
            $table->text('chat_id');
            $table->string('transcript_hash');
            $table->string('status')->default('ready');
            $table->decimal('score', 5, 2)->nullable();
            $table->text('summary')->nullable();
            $table->json('steps')->nullable();
            $table->integer('message_count')->default(0);
            $table->dateTime('last_message_at')->nullable();
            $table->string('model')->nullable();
            $table->dateTime('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['chat_id', 'transcript_hash']);
            $table->index(['artist_id', 'generated_at']);
            $table->index(['lead_id', 'generated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_audits');
    }
};
