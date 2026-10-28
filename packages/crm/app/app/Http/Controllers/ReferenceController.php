<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Internal endpoint used by n8n to attach WhatsApp reference media/links to a
 * Project. Authenticated with a shared header token (`X-Crm-Token`).
 *
 * WAHA media is downloaded server-side with the WAHA API key and stored on the
 * public disk; any other URL is stored as a link (never fetched, so a lead
 * cannot make the CRM fetch arbitrary internal URLs).
 */
class ReferenceController extends Controller
{
    private const MAX_BYTES = 20 * 1024 * 1024;

    private const MAX_REFERENCES = 30;

    public function store(Request $request)
    {
        $expected = (string) config('transport.crm_internal_token');
        $provided = (string) $request->header('X-Crm-Token');

        abort_unless($expected !== '' && hash_equals($expected, $provided), 401);

        $data = $request->validate([
            'artist_id' => ['required', 'uuid'],
            'project_id' => ['required', 'uuid'],
            'url' => ['required', 'url', 'max:2048'],
            'mime' => ['nullable', 'string', 'max:100'],
        ]);

        $project = Project::query()
            ->where('artist_id', $data['artist_id'])
            ->findOrFail($data['project_id']);

        $url = trim($data['url']);
        $stored = $this->isWahaMedia($url)
            ? $this->downloadWahaMedia($project, $url, $data['mime'] ?? null)
            : $url;

        if ($stored === null) {
            return response()->json([
                'ok' => false,
                'error' => 'download_failed',
            ], 422);
        }

        $refs = $project->reference_pics ?? [];
        if (! in_array($stored, $refs, true)) {
            $refs[] = $stored;
            $refs = array_slice($refs, -self::MAX_REFERENCES);
            $project->reference_pics = $refs;
            $project->save();
        }

        return response()->json([
            'ok' => true,
            'reference' => $stored,
            'references' => $project->fresh()->reference_pics ?? [],
        ]);
    }

    private function isWahaMedia(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';

        return str_contains($path, '/api/files/');
    }

    private function downloadWahaMedia(Project $project, string $url, ?string $mime): ?string
    {
        try {
            $response = Http::withHeaders([
                'X-Api-Key' => (string) config('transport.waha_api_key'),
            ])->timeout(30)->get($url);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $body = $response->body();
        if ($body === '' || strlen($body) > self::MAX_BYTES) {
            return null;
        }

        $contentType = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        $mime = $contentType !== '' ? $contentType : strtolower((string) $mime);

        if (! str_starts_with($mime, 'image/')) {
            return null;
        }

        $ext = match ($mime) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'bin',
        };

        $path = 'references/'.$project->artist_id.'/'.$project->id.'/'.Str::uuid().'.'.$ext;

        Storage::disk('public')->put($path, $body);

        return Storage::disk('public')->url($path);
    }
}
