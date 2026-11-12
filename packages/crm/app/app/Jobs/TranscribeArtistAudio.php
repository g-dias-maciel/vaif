<?php

namespace App\Jobs;

use App\Models\Artist;
use App\Services\Agent\AgentMedia;
use App\Services\WahaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Turn an artist's WhatsApp audio into text and append it to the conversation.
 *
 * The webhook records the artist message as "[ÁUDIO ENVIADO]". This job runs on
 * the mock queue (never blocking a live turn), transcribes the media and calls
 * `append_artist_audio_transcript` so the lead page shows what the artist said.
 */
class TranscribeArtistAudio implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(
        public readonly string $artistId,
        public readonly string $chatId,
        public readonly string $waMessageId,
        public readonly ?string $mediaUrl = null,
        public readonly string $mime = '',
    ) {
    }

    public function handle(AgentMedia $media, WahaService $waha): void
    {
        $url = trim((string) $this->mediaUrl);

        if ($url === '') {
            [$url] = $this->resolveFromHistory($waha);
        }

        if ($url === '') {
            return;
        }

        $transcript = $media->transcribe($url);

        if ($transcript === null || trim($transcript) === '') {
            return;
        }

        DB::statement('select append_artist_audio_transcript(?, ?)', [
            $this->waMessageId,
            $transcript,
        ]);
    }

    /**
     * The live webhook usually carries the media URL. When it does not, WAHA
     * history still has it — the same lookup `agent:describe-media` uses.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveFromHistory(WahaService $waha): array
    {
        $artist = Artist::find($this->artistId);

        if ($artist === null) {
            return ['', ''];
        }

        $jid = Str::contains($this->chatId, ':')
            ? Str::after($this->chatId, ':')
            : $this->chatId;

        if ($jid === '') {
            return ['', ''];
        }

        try {
            $messages = $waha->chatMessages($artist, $jid, 50, true);
        } catch (\Throwable $e) {
            Log::warning('Artist audio history failed', ['error' => $e->getMessage()]);

            return ['', ''];
        }

        foreach ($messages ?? [] as $message) {
            if (! is_array($message)) {
                continue;
            }

            $id = trim((string) ($message['id'] ?? ''));

            if ($id === '' || ($id !== $this->waMessageId && ! str_ends_with($this->waMessageId, $id))) {
                continue;
            }

            return [
                (string) ($message['media']['url'] ?? ''),
                (string) ($message['media']['mimetype'] ?? ''),
            ];
        }

        return ['', ''];
    }
}
