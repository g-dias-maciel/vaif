<?php

namespace Tests\Feature;

use App\Models\AgentDraft;
use App\Models\Artist;
use App\Models\ArtistSetting;
use App\Services\Agent\CalibrationReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CalibrationReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'agent.calibration.min_compared' => 2,
            'agent.calibration.min_acceptable_rate' => 0.9,
            'agent.calibration.min_response_rate' => 0.9,
            'agent.calibration.core_intents' => [],
        ]);
    }

    private function artist(): Artist
    {
        $artist = new Artist([
            'id' => (string) Str::uuid(),
            'name' => 'Eva',
            'status' => 'live',
            'wa_session_slug' => 'eval-artist',
        ]);

        $artist->setRelation('settings', new ArtistSetting([
            'artist_id' => $artist->id,
            'display_name' => 'Eva',
            'reply_mode' => 'draft',
        ]));

        return $artist;
    }

    private function draft(Artist $artist, array $overrides = []): AgentDraft
    {
        return AgentDraft::create(array_merge([
            'artist_id' => $artist->id,
            'chat_id' => 'eval:5511@c.us',
            'turn_id' => (string) Str::uuid(),
            'lead_text' => 'Quanto fica?',
            'intent' => 'ask_price',
            'facts' => [],
            'draft_reply' => 'Fica R$ 600,00.',
            'claims' => [['type' => 'price', 'cents' => 60000]],
            'validation' => ['ok' => true, 'resolution' => 'accepted'],
            'status' => AgentDraft::STATUS_COMPARED,
            'artist_reply' => 'Fica R$ 600,00, quer ver os horários?',
            'artist_replied_at' => now()->subMinutes(2),
            'response_seconds' => 90,
            'artist_validation' => ['ok' => true, 'violations' => []],
            'comparison' => [
                'deterministic' => ['ok' => true, 'violations' => []],
                'judge' => [
                    'acceptable' => true,
                    'covered_lead_question' => true,
                    'missing' => [],
                    'factual_conflicts' => [],
                    'missing_handoff' => false,
                    'tone' => 4,
                    'verdict' => 'draft_better',
                    'rationale' => 'ok',
                ],
            ],
            'compared_at' => now(),
            'prompt_version' => 'abc123',
        ], $overrides));
    }

    public function test_a_calibrated_artist_passes_the_hard_gates(): void
    {
        $artist = $this->artist();
        $this->draft($artist);
        $this->draft($artist);

        $report = (new CalibrationReport())->forArtist($artist, 30);

        $this->assertTrue($report['passed']);
        $this->assertSame(2, $report['totals']['compared']);
        $this->assertSame(1.0, $report['rates']['acceptable']);
        $this->assertSame(90, $report['totals']['median_response_seconds']);
    }

    public function test_a_media_only_artist_reply_is_kept_out_of_the_denominator(): void
    {
        $artist = $this->artist();
        $this->draft($artist);
        $this->draft($artist);
        $this->draft($artist, [
            'artist_reply' => '[MÍDIA ENVIADA]',
            'comparison' => [
                'comparable' => false,
                'reason' => 'artist_reply_media_only',
                'deterministic' => ['ok' => true, 'violations' => []],
                'judge' => null,
            ],
        ]);

        $report = (new CalibrationReport())->forArtist($artist, 30);

        $this->assertSame(2, $report['totals']['compared']);
        $this->assertSame(1, $report['totals']['media_only']);
        $this->assertSame(1.0, $report['rates']['acceptable']);
        $this->assertTrue($report['passed']);
    }

    public function test_a_legacy_comparison_with_a_media_reply_is_excluded(): void
    {
        $artist = $this->artist();
        $this->draft($artist);
        $this->draft($artist);
        $this->draft($artist, [
            'artist_reply' => '[MÍDIA ENVIADA]',
            'comparison' => [
                'deterministic' => ['ok' => true, 'violations' => []],
                'judge' => [
                    'acceptable' => true,
                    'covered_lead_question' => true,
                    'missing' => [],
                    'missing_handoff' => false,
                    'tone' => 4,
                    'verdict' => 'tie',
                    'rationale' => 'old comparison without the comparable flag',
                ],
            ],
        ]);

        $report = (new CalibrationReport())->forArtist($artist, 30);

        $this->assertSame(2, $report['totals']['compared']);
        $this->assertSame(1, $report['totals']['media_only']);
        $this->assertSame(1.0, $report['rates']['acceptable']);
    }

    public function test_an_unsafe_draft_blocks_calibration(): void
    {
        $artist = $this->artist();
        $this->draft($artist);
        $this->draft($artist, [
            'validation' => ['ok' => false, 'resolution' => 'kept_legacy'],
        ]);

        $report = (new CalibrationReport())->forArtist($artist, 30);

        $this->assertFalse($report['passed']);
        $this->assertSame(1, $report['totals']['unsafe_drafts']);
    }

    public function test_a_missing_handoff_blocks_calibration(): void
    {
        $artist = $this->artist();
        $this->draft($artist);
        $this->draft($artist, [
            'comparison' => [
                'deterministic' => ['ok' => true, 'violations' => []],
                'judge' => ['acceptable' => true, 'missing_handoff' => true],
            ],
        ]);

        $report = (new CalibrationReport())->forArtist($artist, 30);

        $this->assertFalse($report['passed']);
    }
}
