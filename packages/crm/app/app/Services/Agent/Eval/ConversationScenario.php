<?php

namespace App\Services\Agent\Eval;

use RuntimeException;

/**
 * A fake multi-turn WhatsApp conversation for the end-to-end runner.
 *
 * Unlike the single-turn scenarios, every turn runs through the real
 * PostgreSQL RPCs, so project state, chat history and the handoff silence are
 * exercised exactly like a live conversation.
 */
class ConversationScenario
{
    public function __construct(
        public readonly string $name,
        public readonly array $data,
        public readonly string $file,
    ) {
    }

    /**
     * @return array<int, ConversationScenario>
     */
    public static function load(string $path): array
    {
        if (is_file($path)) {
            return [self::fromFile($path)];
        }

        if (! is_dir($path)) {
            throw new RuntimeException("Conversation path not found: {$path}");
        }

        $files = glob(rtrim($path, '/').'/*.json') ?: [];
        sort($files);

        return array_map(self::fromFile(...), $files);
    }

    public static function fromFile(string $file): self
    {
        $data = json_decode((string) file_get_contents($file), true);

        if (! is_array($data)) {
            throw new RuntimeException("Invalid conversation JSON: {$file}");
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

    public function description(): string
    {
        return (string) ($this->data['description'] ?? '');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function turns(): array
    {
        $turns = $this->data['turns'] ?? null;

        if (is_array($turns) && $turns !== []) {
            return array_values(array_filter($turns, 'is_array'));
        }

        return [[
            'message' => (string) ($this->data['message'] ?? ''),
            'expect' => is_array($this->data['expect'] ?? null) ? $this->data['expect'] : [],
        ]];
    }

    public function phone(): string
    {
        $phone = (string) ($this->data['phone'] ?? '');

        if ($phone !== '') {
            return $phone;
        }

        return '55119'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    }

    public function chatId(string $phone): string
    {
        return (string) ($this->data['chat_id'] ?? 'e2e:'.$phone.'@c.us');
    }

    public function leadName(): ?string
    {
        $name = $this->data['lead_name'] ?? null;

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return is_array($this->data['settings'] ?? null) ? $this->data['settings'] : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function priceBook(): array
    {
        return is_array($this->data['price_book'] ?? null) ? $this->data['price_book'] : [];
    }
}
