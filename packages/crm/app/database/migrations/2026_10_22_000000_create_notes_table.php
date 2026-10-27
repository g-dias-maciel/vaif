<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Internal notes on a Lead. Visible to the Artist and VAIF staff; never
     * surfaced to the Lead. The `leads` table (and its UUID PK) already exists
     * in the shared Postgres and is not created by Laravel.
     */
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('lead_id');
            $table->unsignedBigInteger('author_id');
            $table->text('body');
            $table->timestamps();

            $table->index('lead_id');
            $table->foreign('lead_id')->references('id')->on('leads')->cascadeOnDelete();
            $table->foreign('author_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
