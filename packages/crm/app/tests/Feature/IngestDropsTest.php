<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class IngestDropsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_emails_and_marks_dropped_messages(): void
    {
        Mail::fake();

        config(['crm.admin_email' => 'admin@vaif.test']);

        $id = (string) Str::uuid();

        DB::table('agent_ingest_drops')->insert([
            'id' => $id,
            'artist_id' => null,
            'session_slug' => 'adriano-studio-madri',
            'phone_e164' => '+553599150482',
            'chat_id' => 'adriano-studio-madri:30344346644583@lid',
            'reason' => 'artist_suspended',
            'payload' => json_encode(['event' => 'message']),
            'created_at' => now()->utc(),
        ]);

        $this->artisan('crm:notify-ingest-drops')->assertExitCode(0);

        $this->assertNotNull(DB::table('agent_ingest_drops')->where('id', $id)->value('notified_at'));

        // Nothing new: a second run is a no-op.
        $this->artisan('crm:notify-ingest-drops')->assertExitCode(0);
    }

    public function test_it_does_nothing_without_drops(): void
    {
        Mail::fake();

        $this->artisan('crm:notify-ingest-drops')->assertExitCode(0);
    }
}
