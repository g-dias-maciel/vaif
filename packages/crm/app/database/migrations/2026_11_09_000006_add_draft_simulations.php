<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror of `packages/crm/db/migrations/0035_agent_draft_simulations.sql`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('agent_drafts', 'source')) {
            Schema::table('agent_drafts', function (Blueprint $table) {
                $table->string('source')->default('live');
            });
        }

        if (! Schema::hasColumn('agent_drafts', 'lead_message_id')) {
            Schema::table('agent_drafts', function (Blueprint $table) {
                $table->unsignedBigInteger('lead_message_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('agent_drafts', 'lead_message_id')) {
            Schema::table('agent_drafts', function (Blueprint $table) {
                $table->dropColumn('lead_message_id');
            });
        }

        if (Schema::hasColumn('agent_drafts', 'source')) {
            Schema::table('agent_drafts', function (Blueprint $table) {
                $table->dropColumn('source');
            });
        }
    }
};
