<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `promotion_quotes` was originally added by editing the already-applied
 * `0001_01_01_000000_create_domain_tables` migration, so databases that had
 * already run it (local sqlite dev, any existing environment) never got the
 * table. This migration adds it to those databases without a wipe.
 *
 * On a fresh database the base migration already creates the table, so the
 * guard below makes this a no-op there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('promotion_quotes')) {
            return;
        }

        Schema::create('promotion_quotes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('promotion_id');
            $table->uuid('lead_id');
            $table->uuid('artist_id');
            $table->string('placement');
            $table->string('body_zone');
            $table->integer('first_price');
            $table->integer('second_cap_price');
            $table->dateTime('quoted_at');
            $table->dateTime('valid_until');
            $table->string('status')->default('open');
            $table->dateTime('secured_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['lead_id', 'status']);
            $table->index(['promotion_id', 'status']);
            $table->foreign('promotion_id')->references('id')->on('promotions')->cascadeOnDelete();
            $table->foreign('lead_id')->references('id')->on('leads')->cascadeOnDelete();
            $table->foreign('artist_id')->references('id')->on('artists')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_quotes');
    }
};
