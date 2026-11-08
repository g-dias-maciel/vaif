<?php

namespace App\Console\Commands;

use App\Models\Artist;
use App\Services\WahaService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Move an existing WAHA session's webhook between n8n and the CRM.
 *
 * This uses WAHA's `PUT /api/sessions/{name}` and does **not** re-link the
 * device: WhatsApp authentication is preserved, the artist keeps the same
 * number and does not scan a QR code.
 */
class AgentWebhook extends Command
{
    protected $signature = 'agent:webhook
        {artist? : Artist UUID or WAHA session slug}
        {--all : Apply to every artist with a WAHA session}
        {--url= : Webhook URL (defaults to transport.waha_webhook_url)}
        {--token= : Shared webhook header token}
        {--force : Skip the confirmation prompt for --all}';

    protected $description = 'Point existing WAHA sessions at the CRM (or back at n8n) without re-linking';

    public function handle(): int
    {
        $url = (string) ($this->option('url') ?: config('transport.waha_webhook_url'));
        $token = $this->option('token');
        $token = $token !== null ? (string) $token : null;

        $artists = $this->artists();

        if ($artists === null) {
            return self::FAILURE;
        }

        if ($artists->isEmpty()) {
            $this->error('No artist with a WAHA session matched.');

            return self::FAILURE;
        }

        if ($this->option('all') && ! $this->option('force')
            && ! $this->confirm("Apply the webhook to {$artists->count()} session(s)?")) {
            return self::FAILURE;
        }

        $waha = WahaService::make();
        $rows = [];
        $failed = 0;

        $events = str_contains($url, 'n8n.')
            ? ['message', 'presence.update']
            : ['message.any', 'presence.update'];

        foreach ($artists as $artist) {
            $ok = $waha->updateWebhook($artist, $url, $token, $events);
            if (! $ok) {
                $failed++;
            }

            $rows[] = [
                $artist->name,
                $artist->wa_session_slug,
                $artist->status,
                $ok ? 'ok' : 'failed',
            ];
        }

        $this->table(['artist', 'session', 'status', 'webhook'], $rows);
        $this->line('URL: '.$url);

        if ($failed > 0) {
            $this->error("{$failed} session(s) failed. Check WAHA reachability and the API key.");

            return self::FAILURE;
        }

        $this->info('Webhook updated in place; no QR re-scan needed.');

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Artist>|null
     */
    private function artists()
    {
        if ($this->option('all')) {
            return Artist::query()->whereNotNull('wa_session_slug')->orderBy('name')->get();
        }

        $key = (string) $this->argument('artist');

        if ($key === '') {
            $this->error('Pass an artist UUID/session slug, or --all.');

            return null;
        }

        $query = Artist::query()->where('wa_session_slug', $key);

        if (Str::isUuid($key)) {
            $query->orWhere('id', $key);
        }

        return $query->get();
    }
}
