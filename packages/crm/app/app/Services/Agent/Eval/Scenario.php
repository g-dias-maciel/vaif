<?php

namespace App\Services\Agent\Eval;

use App\Models\Artist;
use App\Models\ArtistSetting;
use RuntimeException;

/**
 * A fake-case definition: the CRM fixtures a turn would see, the lead message,
 * the scripted model answers (simulate) and the expected grounded behaviour.
 */
class Scenario
{
    public function __construct(
        public readonly string $name,
        public readonly array $data,
        public readonly string $file,
    ) {
    }

    /**
     * @return array<int, Scenario>
     */
    public static function load(string $path): array
    {
        if (is_file($path)) {
            return [self::fromFile($path)];
        }

        if (! is_dir($path)) {
            throw new RuntimeException("Scenario path not found: {$path}");
        }

        $files = glob(rtrim($path, '/').'/*.json') ?: [];
        sort($files);

        return array_map(self::fromFile(...), $files);
    }

    public static function fromFile(string $file): self
    {
        $json = file_get_contents($file);
        $data = json_decode((string) $json, true);

        if (! is_array($data)) {
            throw new RuntimeException("Invalid scenario JSON: {$file}");
        }

        return new self(
            (string) ($data['name'] ?? basename($file, '.json')),
            $data,
            $file,
        );
    }

    public function name(): string
    {
        return $this->name;
    }

    public function message(): string
    {
        return (string) ($this->data['message'] ?? '');
    }

    public function chatId(): string
    {
        return (string) ($this->data['chat_id'] ?? 'eval:5511999999999@c.us');
    }

    public function phone(): string
    {
        return (string) ($this->data['phone'] ?? '5511999999999');
    }

    public function leadName(): ?string
    {
        $name = $this->data['turn']['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    public function script(): array
    {
        return is_array($this->data['script'] ?? null) ? $this->data['script'] : [];
    }

    public function expect(): array
    {
        return is_array($this->data['expect'] ?? null) ? $this->data['expect'] : [];
    }

    public function artist(): Artist
    {
        $data = is_array($this->data['artist'] ?? null) ? $this->data['artist'] : [];

        $artist = new Artist([
            'id' => (string) ($data['id'] ?? '11111111-1111-4111-8111-111111111111'),
            'name' => (string) ($data['name'] ?? 'Estúdio Eva'),
            'status' => (string) ($data['status'] ?? 'live'),
            'timezone' => (string) ($data['timezone'] ?? 'America/Sao_Paulo'),
            'wa_session_slug' => (string) ($data['wa_session_slug'] ?? 'eval-artist'),
        ]);

        $settings = array_merge([
            'artist_id' => $artist->id,
            'display_name' => $data['name'] ?? 'Eva',
            'specialties' => ['fine line', 'realismo'],
            'excluded_styles' => [],
            'full_selling' => true,
            'blocked_numbers' => [],
            'floor_pct' => 80,
            'deposit_type' => 'percent',
            'deposit_value' => 30,
            'pix_key' => 'pix@estudio.test',
            'instagram_handle' => '@estudio.eva',
            'portfolio_url' => 'https://instagram.com/estudio.eva',
            'whatsapp_number' => '5511999999999',
            'address' => 'Rua das Tattoos, 10',
            'city' => 'São Paulo',
            'telegram_group_id' => null,
            'ai_active_hours' => null,
            'reschedule_policy_hours' => 48,
            'cancellation_policy_hours' => 48,
            'payment_methods' => ['pix', 'credit_card', 'cash'],
            'allow_hold_slot' => false,
            'guarantee_price' => false,
        ], is_array($data['settings'] ?? null) ? $data['settings'] : []);

        $artist->setRelation('settings', new ArtistSetting($settings));

        return $artist;
    }
}
