<?php

namespace App\Http\Controllers;

use App\Models\AgentDraft;
use App\Models\AgentDraftReview;
use App\Models\Artist;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Staff review of a copilot draft. A `needs_fix` review stores what Beatriz
 * should have said, so the case can be used as a calibration target.
 */
class CalibrationReviewController extends Controller
{
    public function store(Request $request, Artist $artist, AgentDraft $draft)
    {
        abort_unless($draft->artist_id === $artist->id, 404);

        $data = $request->validate([
            'verdict' => ['required', Rule::in([
                AgentDraftReview::VERDICT_GOOD,
                AgentDraftReview::VERDICT_NEEDS_FIX,
            ])],
            'category' => ['nullable', Rule::in(array_keys(AgentDraftReview::CATEGORIES))],
            'note' => ['nullable', 'string', 'max:2000'],
            'corrected_reply' => ['nullable', 'string', 'max:2000'],
        ]);

        $note = trim((string) ($data['note'] ?? ''));
        $corrected = trim((string) ($data['corrected_reply'] ?? ''));

        if ($data['verdict'] === AgentDraftReview::VERDICT_NEEDS_FIX && $note === '' && $corrected === '') {
            return back()->with('error', 'Explique o que deveria ser diferente ou escreva a resposta correta.');
        }

        AgentDraftReview::updateOrCreate(
            ['agent_draft_id' => $draft->id],
            [
                'artist_id' => $artist->id,
                'lead_id' => $draft->lead_id,
                'reviewer_id' => $request->user()?->id,
                'verdict' => $data['verdict'],
                'category' => $data['category'] ?? null,
                'note' => $note !== '' ? $note : null,
                'corrected_reply' => $corrected !== '' ? $corrected : null,
            ],
        );

        return back()->with('status', 'Revisão salva.');
    }

    public function destroy(Artist $artist, AgentDraft $draft)
    {
        abort_unless($draft->artist_id === $artist->id, 404);

        AgentDraftReview::query()
            ->where('artist_id', $artist->id)
            ->where('agent_draft_id', $draft->id)
            ->delete();

        return back()->with('status', 'Revisão removida.');
    }
}
