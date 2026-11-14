<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agent_draft_reviews')) {
            return;
        }

        Schema::create('agent_draft_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('artist_id');
            $table->uuid('agent_draft_id')->unique();
            $table->uuid('lead_id')->nullable();
            $table->unsignedBigInteger('reviewer_id')->nullable();
            $table->string('verdict')->default('needs_fix');
            $table->string('category')->nullable();
            $table->text('note')->nullable();
            $table->text('corrected_reply')->nullable();
            $table->timestamps();
            $table->index(['artist_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_draft_reviews');
    }
};
