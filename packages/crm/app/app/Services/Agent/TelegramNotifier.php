<?php

namespace App\Services\Agent;

use App\Models\Artist;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramNotifier implements HandoffNotifier
{
    public function notify(Artist $artist, array $lead, array $facts, array $apply): void
    {
        $token = (string) config('agent.telegram.token');
        $chatId = (string) ($artist->settings?->telegram_group_id ?? '');

        if ($token === '' || $chatId === '') {
            return;
        }

        $reason = $this->reason((string) ($apply['handoff_reason'] ?? ''));
        $name = trim((string) ($lead['name'] ?? '')) ?: 'Cliente';
        $phone = (string) ($lead['phone_e164'] ?? '');
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        $lines = [
            '<b>🔔 Atendimento para você</b>',
            '<b>Lead:</b> '.$name,
            '<b>Motivo:</b> '.$reason,
        ];

        if ($digits !== '') {
            $lines[] = '<b>Telefone:</b> +'.$digits;
            $lines[] = '👉 <a href="https://wa.me/'.$digits.'">Abrir no WhatsApp</a>';
        }

        $lines[] = '<i>Responda pelo CRM ou pelo WhatsApp.</i>';

        try {
            Http::timeout(10)->post('https://api.telegram.org/bot'.$token.'/sendMessage', [
                'chat_id' => $chatId,
                'text' => implode("\n", $lines),
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Telegram handoff notification failed', ['error' => $e->getMessage()]);
        }
    }

    private function reason(string $reason): string
    {
        return [
            'cover_up' => 'Cover-up',
            'reforma' => 'Reforma',
            'lead_requested_artist' => 'Pediu o artista',
            'below_piso' => 'Abaixo do piso',
            'vague' => 'Descrição vaga',
            'audio_2x' => 'Áudio repetido',
            'no_availability' => 'Sem horário disponível',
            'pix_missing' => 'PIX não configurado',
            'no_price_row' => 'Sem preço para o local',
            'gate_stuck' => 'Preço não liberado',
            'ambiguous_reference' => 'Referência ambígua',
            'client_service' => 'Cliente antigo / pós-tatuagem',
        ][$reason] ?? ($reason ?: 'Atendimento solicitado');
    }
}
