<?php

namespace App\Services\Sales;

use App\Models\Artist;
use App\Models\Prospect;
use App\Models\ProspectActivity;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Imports a normalized row from the Notion pipeline database.
 *
 * Routing rule:
 *  - `Status Contrato = Ativo` → Artist + active prospect in `ganho`
 *    (no contract and no login: the commercial link is made manually).
 *  - anything else → backlog prospect with `legacy_status` + flags.
 *
 * The importer is idempotent: it matches by Notion page id, then by normalized
 * WhatsApp, and never duplicates an Artist or a Prospect.
 */
class NotionPipelineImporter
{
    public const FLAG_NOTION_BACKLOG = 'notion_backlog';

    public const FLAG_NOTION_CLIENT = 'notion_cliente';

    public const FLAG_SEM_NOME = 'sem_nome';

    public const FLAG_SEM_EMAIL = 'sem_email';

    public const FLAG_SEM_VALOR = 'sem_valor_fechamento';

    public const FLAG_SEM_INSTAGRAM = 'sem_instagram';

    public const FLAG_CLIENTE_SEM_ATIVO = 'cliente_sem_status_ativo';

    public const FLAG_CLIENTE_PAUSADO = 'cliente_pausado';

    public const FLAG_TELEFONE_INVALIDO = 'pipeline_telefone_invalido';

    public const FLAG_DUPLICADO = 'duplicado_mesclado';

    public const FLAG_ARTISTA_SUSPENSO = 'artista_suspenso';

    public const FLAG_ARTISTA_EXISTENTE = 'artista_existente';

    public const FLAG_PROMOVIDO = 'promovido_do_backlog';

    public const FLAG_EMAIL_INVALIDO = 'email_invalido';

    /** @var array<string, Artist> normalized phone => artist */
    private array $artistByPhone = [];

    /** @var array<string, Artist> lower-case name => artist */
    private array $artistByName = [];

    private bool $artistsLoaded = false;

    /**
     * Import one normalized Notion page.
     *
     * @param  array<string, mixed>  $row
     * @return array{action: string, flags: array<int, string>, phone: ?string, artist_id: ?string}
     */
    public function importRow(array $row, bool $dryRun = false, ?int $ownerId = null): array
    {
        $this->loadArtists();
        $data = $this->normalizeRow($row);

        if ($data['phone'] === null) {
            return [
                'action' => 'skip',
                'reason' => 'telefone_invalido',
                'flags' => $data['flags'],
                'phone' => null,
                'artist_id' => null,
            ];
        }

        DB::beginTransaction();

        try {
            $result = $this->apply($data, $ownerId);
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $dryRun ? DB::rollBack() : DB::commit();

        return $result;
    }

    /**
     * Normalize a raw Notion row into the canonical shape used by the importer.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function normalizeRow(array $row): array
    {
        $flags = [];
        $rawName = $this->cleanString($row['name'] ?? null);
        $phone = self::normalizePhone($row['whatsapp'] ?? null);
        $instagram = $this->extractInstagram($row['instagram'] ?? null);
        $email = $this->cleanEmail($row['email'] ?? null);

        if ($email !== null && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $flags[] = self::FLAG_EMAIL_INVALIDO;
            $email = null;
        }

        if ($instagram === null) {
            $flags[] = self::FLAG_SEM_INSTAGRAM;
        }

        $statusContrato = mb_strtolower($this->cleanString($row['status_contrato'] ?? null));
        $pipeline = $this->cleanString($row['pipeline'] ?? null);
        $pipelineKey = mb_strtolower($pipeline);
        $isActiveClient = $statusContrato === 'ativo';

        if ($rawName === '') {
            $flags[] = self::FLAG_SEM_NOME;
        }

        $name = $rawName !== ''
            ? $rawName
            : ($instagram ?: $this->formatPhone($phone) ?: 'Sem nome');

        $valueCents = $this->parseMoney($row['valor_fechamento'] ?? null);
        $nextActionAt = $this->parseDate($row['proximo_contato'] ?? null, 'd/m/Y');
        $lastEditedAt = $this->parseDate($row['last_edited_time'] ?? null);

        if ($isActiveClient) {
            $flags[] = self::FLAG_NOTION_CLIENT;

            if ($email === null) {
                $flags[] = self::FLAG_SEM_EMAIL;
            }

            if ($valueCents === null) {
                $flags[] = self::FLAG_SEM_VALOR;
            }
        } else {
            $flags[] = self::FLAG_NOTION_BACKLOG;

            if ($pipelineKey === 'cliente') {
                $flags[] = self::FLAG_CLIENTE_SEM_ATIVO;
            }

            if ($statusContrato === 'pausado') {
                $flags[] = self::FLAG_CLIENTE_PAUSADO;
            }

            if ($pipelineKey === 'telefone invalido') {
                $flags[] = self::FLAG_TELEFONE_INVALIDO;
            }
        }

        return [
            'notion_page_id' => $this->cleanString($row['notion_page_id'] ?? null),
            'name' => $name,
            'raw_name' => $rawName,
            'whatsapp' => $this->cleanString($row['whatsapp'] ?? null),
            'phone' => $phone,
            'instagram' => $instagram,
            'email' => $email,
            'status_contrato' => $statusContrato,
            'pipeline' => $pipeline,
            'is_active_client' => $isActiveClient,
            'value_cents' => $valueCents,
            'start_date' => $this->parseDate($row['inicio_contrato'] ?? null)?->toDateString(),
            'next_action_at' => $nextActionAt,
            'last_edited_at' => $lastEditedAt,
            'address' => $this->cleanString($row['address'] ?? null),
            'flags' => array_values(array_unique($flags)),
            'raw' => $row['raw'] ?? $row,
            'merged_count' => (int) ($row['merged_count'] ?? 1),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{action: string, flags: array<int, string>, phone: ?string, artist_id: ?string}
     */
    private function apply(array $data, ?int $ownerId): array
    {
        $phone = (string) $data['phone'];
        $prospect = Prospect::where('whatsapp_e164', $phone)->first();

        if (! $prospect && filled($data['notion_page_id'])) {
            $prospect = Prospect::where('legacy_notion_id', $data['notion_page_id'])->first();
        }

        $flags = $data['flags'];

        if ($data['merged_count'] > 1) {
            $flags[] = self::FLAG_DUPLICADO;
        }

        return $data['is_active_client']
            ? $this->applyClient($data, $prospect, $flags, $ownerId)
            : $this->applyBacklog($data, $prospect, $flags, $ownerId);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $flags
     * @return array{action: string, flags: array<int, string>, phone: ?string, artist_id: ?string}
     */
    private function applyClient(array $data, ?Prospect $prospect, array $flags, ?int $ownerId): array
    {
        $artist = $this->resolveArtist($data, $prospect);
        $artistAction = 'linked';

        if ($artist === null) {
            $artist = $this->createArtist($data);
            $artistAction = 'created';
        } else {
            $flags[] = self::FLAG_ARTISTA_EXISTENTE;

            if ($artist->status === 'suspended') {
                $flags[] = self::FLAG_ARTISTA_SUSPENSO;
            }

            $this->fillArtistGaps($artist, $data);
        }

        $isNew = $prospect === null;

        if ($isNew) {
            $prospect = new Prospect;
            $prospect->source = 'notion';
        }

        $wasBacklog = $prospect->isBacklog() || $prospect->isArchived();

        $prospect->fill([
            'name' => $this->nameFor($data, $prospect),
            'whatsapp_e164' => $data['phone'],
            'whatsapp_raw' => $data['whatsapp'],
            'instagram' => $data['instagram'],
            'email' => $data['email'],
            'status' => Prospect::STATUS_ACTIVE,
            'stage_key' => 'ganho',
            'artist_id' => $artist->id,
            'handoff_at' => $prospect->handoff_at ?? now(),
            'next_action_at' => null,
            'lost_reason' => null,
            'legacy_notion_id' => $data['notion_page_id'] ?: $prospect->legacy_notion_id,
            'legacy_status' => $data['pipeline'] ?: $prospect->legacy_status,
            'raw_payload' => $this->rawPayload($data),
        ]);

        if ($isNew) {
            $prospect->source = 'notion';
        } elseif ($ownerId !== null && $prospect->owner_id === null) {
            $prospect->owner_id = $ownerId;
        }

        $prospect->import_flags = $this->mergeFlags($prospect->import_flags, $flags);

        if ($isNew && $ownerId !== null) {
            $prospect->owner_id = $ownerId;
        }

        if ($wasBacklog) {
            $prospect->import_flags = $this->mergeFlags($prospect->import_flags, [self::FLAG_PROMOVIDO]);
        }

        $prospect->save();

        if ($isNew || $wasBacklog) {
            $this->log($prospect, $isNew ? 'Cliente importado do Notion' : 'Prospect promovido a cliente pelo Notion', $data, $artist);
        }

        return [
            'action' => $isNew ? 'created_client' : ($wasBacklog ? 'promoted_client' : 'updated_client'),
            'flags' => $prospect->import_flags ?? [],
            'phone' => $prospect->whatsapp_e164,
            'artist_id' => $artist->id,
            'artist_action' => $artistAction,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $flags
     * @return array{action: string, flags: array<int, string>, phone: ?string, artist_id: ?string}
     */
    private function applyBacklog(array $data, ?Prospect $prospect, array $flags, ?int $ownerId): array
    {
        $isNew = $prospect === null;

        if ($isNew) {
            $prospect = new Prospect;
            $prospect->status = Prospect::STATUS_BACKLOG;
            $prospect->source = 'notion';

            if ($ownerId !== null) {
                $prospect->owner_id = $ownerId;
            }
        }

        // Never downgrade an active/ganho prospect because Notion still shows
        // it in a lead stage. The sales state is authoritative once it exists.
        if ($prospect->isActive()) {
            $prospect->import_flags = $this->mergeFlags($prospect->import_flags, array_merge($flags, ['notion_backlog_ignorado']));
            $prospect->save();

            return [
                'action' => 'kept_active',
                'flags' => $prospect->import_flags ?? [],
                'phone' => $prospect->whatsapp_e164,
                'artist_id' => $prospect->artist_id,
            ];
        }

        $prospect->fill([
            'name' => $this->nameFor($data, $prospect),
            'whatsapp_e164' => $data['phone'],
            'whatsapp_raw' => $data['whatsapp'],
            'instagram' => $data['instagram'] !== null ? $data['instagram'] : $prospect->instagram,
            'email' => $data['email'] !== null ? $data['email'] : $prospect->email,
            'legacy_notion_id' => $data['notion_page_id'] ?: $prospect->legacy_notion_id,
            'legacy_status' => $data['pipeline'] ?: $prospect->legacy_status,
            'raw_payload' => $this->rawPayload($data),
        ]);

        if ($data['next_action_at'] !== null) {
            $prospect->next_action_at = $data['next_action_at'];
        }

        if ($ownerId !== null && $prospect->owner_id === null) {
            $prospect->owner_id = $ownerId;
        }

        $prospect->import_flags = $this->mergeFlags($prospect->import_flags, $flags);
        $prospect->save();

        if ($isNew) {
            $this->log($prospect, 'Lead importado do Notion', $data, null);
        }

        return [
            'action' => $isNew ? 'created_backlog' : 'updated_backlog',
            'flags' => $prospect->import_flags ?? [],
            'phone' => $prospect->whatsapp_e164,
            'artist_id' => $prospect->artist_id,
        ];
    }

    /**
     * An existing prospect with a linked Artist always wins; otherwise match by
     * phone, then by exact name.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveArtist(array $data, ?Prospect $prospect): ?Artist
    {
        if ($prospect?->artist_id !== null) {
            $artist = Artist::find($prospect->artist_id);

            if ($artist !== null) {
                return $artist;
            }
        }

        $phoneKey = $this->phoneKey($data['phone']);

        if ($phoneKey !== null && isset($this->artistByPhone[$phoneKey])) {
            return $this->artistByPhone[$phoneKey];
        }

        $nameKey = $this->nameKey($data['name']);

        return $this->artistByName[$nameKey] ?? null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createArtist(array $data): Artist
    {
        $artist = Artist::create([
            'name' => $data['name'],
            'status' => 'onboarding',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => $this->uniqueSlug($data['name']),
        ]);

        $artist->settings()->create([
            'display_name' => $data['name'],
            'whatsapp_number' => preg_replace('/\D+/', '', (string) $data['phone']) ?: null,
            'instagram_handle' => $data['instagram'],
            'address' => $data['address'],
            'deposit_type' => 'percent',
            'deposit_value' => 30,
            'working_hours' => $this->defaultWorkingHours(),
        ]);

        // Keep the in-memory map fresh so a second row in the same run links to
        // this Artist instead of creating a duplicate.
        $this->artistByPhone[$this->phoneKey($data['phone'])] = $artist;
        $this->artistByName[$this->nameKey($data['name'])] = $artist;

        return $artist;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fillArtistGaps(Artist $artist, array $data): void
    {
        $changes = [];

        if (blank($artist->name) && filled($data['name'])) {
            $changes['name'] = $data['name'];
        }

        if ($changes !== []) {
            $artist->update($changes);
        }

        $settings = $artist->settings()->firstOrCreate(['artist_id' => $artist->id]);
        $settingsChanges = [];

        if (blank($settings->display_name) && filled($data['name'])) {
            $settingsChanges['display_name'] = $data['name'];
        }

        if (blank($settings->whatsapp_number) && filled($data['phone'])) {
            $settingsChanges['whatsapp_number'] = preg_replace('/\D+/', '', (string) $data['phone']);
        }

        if (blank($settings->instagram_handle) && filled($data['instagram'])) {
            $settingsChanges['instagram_handle'] = $data['instagram'];
        }

        if (blank($settings->address) && filled($data['address'])) {
            $settingsChanges['address'] = $data['address'];
        }

        if ($settingsChanges !== []) {
            $settings->update($settingsChanges);
        }
    }

    private function loadArtists(): void
    {
        if ($this->artistsLoaded) {
            return;
        }

        $this->artistsLoaded = true;

        Artist::query()->with('settings')->chunkById(200, function ($artists): void {
            foreach ($artists as $artist) {
                $this->artistByName[$this->nameKey($artist->name)] = $artist;

                $phone = $artist->settings?->whatsapp_number;

                if (filled($phone)) {
                    $key = $this->phoneKey($phone);

                    if ($key !== null) {
                        $this->artistByPhone[$key] = $artist;
                    }
                }
            }
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function rawPayload(array $data): array
    {
        return [
            'notion' => $data['raw'],
            'derived' => [
                'status_contrato' => $data['status_contrato'],
                'pipeline' => $data['pipeline'],
                'value_cents' => $data['value_cents'],
                'start_date' => $data['start_date'],
                'last_edited_at' => $data['last_edited_at']?->toIso8601String(),
                'merged_count' => $data['merged_count'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function log(Prospect $prospect, string $title, array $data, ?Artist $artist): void
    {
        $prospect->activities()->create([
            'type' => ProspectActivity::TYPE_IMPORT,
            'title' => $title,
            'metadata' => [
                'source' => 'notion',
                'artist_id' => $artist?->id,
                'pipeline' => $data['pipeline'],
                'status_contrato' => $data['status_contrato'],
                'notion_page_id' => $data['notion_page_id'],
                'merged_count' => $data['merged_count'],
            ],
            'completed_at' => now(),
        ]);
    }

    /**
     * @param  array<int, string>|null  $current
     * @param  array<int, string>  $incoming
     * @return array<int, string>
     */
    private function mergeFlags(?array $current, array $incoming): array
    {
        return array_values(array_unique(array_merge($current ?? [], $incoming)));
    }

    public static function normalizePhone(mixed $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw) ?? '';

        if ($digits === '') {
            return null;
        }

        // Some Notion rows have a leading zero before the area code.
        if (strlen($digits) === 12 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        if (str_starts_with($digits, '55') && strlen($digits) > 11) {
            return strlen($digits) <= 13 ? '+'.$digits : null;
        }

        if (strlen($digits) === 10 || strlen($digits) === 11) {
            return '+55'.$digits;
        }

        return null;
    }

    private function phoneKey(mixed $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        return $digits !== '' ? $digits : null;
    }

    /**
     * Keep an existing, real name when the Notion title is empty and only the
     * Instagram fallback is available.
     *
     * @param  array<string, mixed>  $data
     */
    private function nameFor(array $data, ?Prospect $prospect): string
    {
        if (filled($data['raw_name']) || blank($prospect?->name)) {
            return $data['name'];
        }

        return $prospect->name;
    }

    private function nameKey(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $name) ?? ''));
    }

    private function extractInstagram(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (preg_match('~instagram\.com/([^/?#]+)~i', $value, $match)) {
            $value = $match[1];
        } elseif (str_contains(mb_strtolower($value), 'instagram.com')) {
            return null;
        }

        $value = trim($value, "@/ \t\n\r\0\x0B");
        $value = preg_replace('/\s+/', '', $value) ?? '';

        return $value !== '' ? $value : null;
    }

    private function cleanString(mixed $value): string
    {
        return trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');
    }

    private function cleanEmail(mixed $value): ?string
    {
        $value = mb_strtolower($this->cleanString($value));

        return $value !== '' ? $value : null;
    }

    private function formatPhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '55') && strlen($digits) >= 12) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 11) {
            return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 5), substr($digits, 7));
        }

        if (strlen($digits) === 10) {
            return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 4), substr($digits, 6));
        }

        return $phone;
    }

    private function parseDate(mixed $value, ?string $format = null): ?Carbon
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            if ($format !== null) {
                return Carbon::createFromFormat($format, substr($value, 0, 10))->startOfDay();
            }

            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function parseMoney(mixed $value): ?int
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $clean = preg_replace('/[^0-9,.\-]/', '', $value) ?? '';

        if ($clean === '' || $clean === '-') {
            return null;
        }

        if (str_contains($clean, ',')) {
            $clean = str_replace('.', '', $clean);
            $clean = str_replace(',', '.', $clean);
        } elseif (substr_count($clean, '.') > 1) {
            $clean = str_replace('.', '', $clean);
        }

        return is_numeric($clean) ? (int) round((float) $clean * 100) : null;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'artist';
        $slug = $base;
        $i = 2;

        while (Artist::where('wa_session_slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function defaultWorkingHours(): array
    {
        return [
            'seg' => ['09:00-12:00', '14:00-18:00'],
            'ter' => ['09:00-12:00', '14:00-18:00'],
            'qua' => ['09:00-12:00', '14:00-18:00'],
            'qui' => ['09:00-12:00', '14:00-18:00'],
            'sex' => ['09:00-12:00', '14:00-18:00'],
            'sab' => ['09:00-13:00'],
        ];
    }
}
