<?php

namespace App\Services\Agent;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Watches the OpenRouter credit balance.
 *
 * The agent silently degrades when the account runs out: preflight, answers,
 * audio transcription, claim extraction and comparison all return 402. This
 * service checks the balance on a schedule and alerts the admin once per
 * throttle window, and also sends an immediate alert when a live turn hits 402.
 */
class CreditMonitor
{
    private const CACHE_KEY = 'agent:credits:low-alert';

    /**
     * @return array{total: float, usage: float, remaining: float}|null
     */
    public function balance(): ?array
    {
        $key = (string) config('agent.openrouter.key');

        if ($key === '') {
            return null;
        }

        try {
            $response = Http::withToken($key)
                ->timeout(10)
                ->get('https://openrouter.ai/api/v1/credits');
        } catch (Throwable $e) {
            Log::warning('OpenRouter credits check failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('OpenRouter credits check returned HTTP '.$response->status());

            return null;
        }

        $data = $response->json('data');
        $total = (float) ($data['total_credits'] ?? 0);
        $usage = (float) ($data['total_usage'] ?? 0);

        return [
            'total' => $total,
            'usage' => $usage,
            'remaining' => round($total - $usage, 4),
        ];
    }

    /**
     * @return array{ok: bool, balance: ?array, alerted: bool}
     */
    public function checkAndAlert(bool $force = false): array
    {
        $balance = $this->balance();

        if ($balance === null) {
            return ['ok' => false, 'balance' => null, 'alerted' => false];
        }

        $threshold = (float) config('agent.credits.warn_below', 5.0);

        if (! $force && $balance['remaining'] > $threshold) {
            Cache::forget(self::CACHE_KEY);

            return ['ok' => true, 'balance' => $balance, 'alerted' => false];
        }

        $alerted = $this->sendAlert(
            $force && $balance['remaining'] > $threshold
                ? 'Créditos da IA — teste de alerta'
                : 'Créditos da IA acabando',
            $balance,
            [],
            $force,
        );

        return ['ok' => true, 'balance' => $balance, 'alerted' => $alerted];
    }

    /**
     * Called from a live turn when OpenRouter returns 402.
     *
     * @param  array<string, mixed>  $context
     */
    public function alertExhausted(array $context = []): bool
    {
        return $this->sendAlert(
            'Créditos da IA acabaram',
            $this->balance(),
            $context,
            false,
        );
    }

    /**
     * @param  array{total: float, usage: float, remaining: float}|null  $balance
     * @param  array<string, mixed>  $context
     */
    private function sendAlert(string $subject, ?array $balance, array $context, bool $force): bool
    {
        if (! $force && Cache::has(self::CACHE_KEY)) {
            return false;
        }

        $to = (string) (config('agent.credits.email') ?: config('crm.admin_email'));

        if ($to === '') {
            Log::critical('[VAIF] AI credits alert has no recipient', [
                'subject' => $subject,
                'balance' => $balance,
                'context' => $context,
            ]);

            return false;
        }

        $remaining = $balance !== null ? number_format($balance['remaining'], 2, ',', '.') : 'desconhecido';
        $total = $balance !== null ? number_format($balance['total'], 2, ',', '.') : 'desconhecido';
        $usage = $balance !== null ? number_format($balance['usage'], 2, ',', '.') : 'desconhecido';

        $lines = [
            $subject,
            '',
            'Saldo restante: '.$remaining.' créditos',
            'Total contratado: '.$total.' créditos',
            'Uso acumulado: '.$usage.' créditos',
            '',
            'Sem saldo, a Beatriz não consegue:',
            '- transcrever áudios;',
            '- classificar a intenção do lead;',
            '- gerar respostas;',
            '- comparar rascunhos com o artista.',
            '',
            'Recarregue em https://openrouter.ai/settings/credits',
        ];

        if ($context !== []) {
            $lines[] = '';
            $lines[] = 'Contexto do último erro:';
            $lines[] = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        try {
            Mail::raw(implode("\n", $lines), function ($message) use ($to, $subject): void {
                $message->to($to)->subject('[VAIF] '.$subject);
            });
        } catch (Throwable $e) {
            Log::critical('[VAIF] AI credits alert e-mail failed', [
                'to' => $to,
                'error' => $e->getMessage(),
                'balance' => $balance,
            ]);

            return false;
        }

        Cache::put(
            self::CACHE_KEY,
            now()->timestamp,
            now()->addMinutes(max(1, (int) config('agent.credits.throttle_minutes', 120))),
        );

        Log::critical('[VAIF] AI credits alert sent', [
            'to' => $to,
            'subject' => $subject,
            'balance' => $balance,
            'context' => $context,
        ]);

        return true;
    }
}
