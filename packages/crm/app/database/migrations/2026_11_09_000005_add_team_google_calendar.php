<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror of `packages/crm/db/migrations/0038_team_google_calendar.sql` for the
 * SQLite test schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        if (! Schema::hasColumn('users', 'google_calendar_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('google_calendar_id')->nullable();
                $table->string('google_calendar_summary')->nullable();
                $table->string('google_calendar_timezone')->nullable();
                $table->timestamp('google_connected_at')->nullable();
            });
        }

        if (! Schema::hasColumn('team_events', 'google_event_id')) {
            Schema::table('team_events', function (Blueprint $table) {
                $table->string('google_event_id')->nullable();
                $table->string('google_calendar_id')->nullable();
                $table->boolean('google_sync_pending')->default(false);
                $table->timestamp('google_synced_at')->nullable();
                $table->text('google_sync_error')->nullable();
                $table->softDeletes();

                $table->index('google_sync_pending');
                $table->index('google_calendar_id');
            });
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        if (Schema::hasColumn('team_events', 'google_event_id')) {
            Schema::table('team_events', function (Blueprint $table) {
                $table->dropColumn([
                    'google_event_id',
                    'google_calendar_id',
                    'google_sync_pending',
                    'google_synced_at',
                    'google_sync_error',
                    'deleted_at',
                ]);
            });
        }

        if (Schema::hasColumn('users', 'google_calendar_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn([
                    'google_calendar_id',
                    'google_calendar_summary',
                    'google_calendar_timezone',
                    'google_connected_at',
                ]);
            });
        }
    }
};
