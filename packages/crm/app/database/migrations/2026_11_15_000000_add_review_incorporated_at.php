<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agent_draft_reviews') || Schema::hasColumn('agent_draft_reviews', 'incorporated_at')) {
            return;
        }

        Schema::table('agent_draft_reviews', function (Blueprint $table) {
            $table->timestamp('incorporated_at')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('agent_draft_reviews', 'incorporated_at')) {
            return;
        }

        Schema::table('agent_draft_reviews', function (Blueprint $table) {
            $table->dropColumn('incorporated_at');
        });
    }
};
