<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agent_drafts') || Schema::hasColumn('agent_drafts', 'artist_media_note')) {
            return;
        }

        Schema::table('agent_drafts', function (Blueprint $table) {
            $table->text('artist_media_note')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('agent_drafts', 'artist_media_note')) {
            return;
        }

        Schema::table('agent_drafts', function (Blueprint $table) {
            $table->dropColumn('artist_media_note');
        });
    }
};
