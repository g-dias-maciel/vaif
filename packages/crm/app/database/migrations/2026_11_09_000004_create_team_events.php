<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror of `packages/crm/db/migrations/0037_team_events.sql` for the SQLite
 * test schema. Production applies the SQL migration directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        if (! Schema::hasTable('team_events')) {
            Schema::create('team_events', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('title');
                $table->string('type')->default('other');
                $table->timestamp('starts_at');
                $table->timestamp('ends_at');
                $table->string('location')->nullable();
                $table->string('meeting_url')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('owner_id')->nullable();
                $table->uuid('prospect_id')->nullable();
                $table->timestamps();

                $table->foreign('owner_id')->references('id')->on('users')->nullOnDelete();
                $table->foreign('prospect_id')->references('id')->on('prospects')->nullOnDelete();
                $table->index(['starts_at', 'ends_at']);
                $table->index('type');
                $table->index('owner_id');
                $table->index('prospect_id');
            });
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        Schema::dropIfExists('team_events');
    }
};
