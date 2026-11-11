<?php

namespace App\Services\Agent;

use App\Models\Artist;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Audio transcription and reference-image analysis for the WhatsApp agent.
 *
 * WhatsApp media is carried through the debounce buffer as markers. This class
 * strips them, turns audio into text and turns the first image into a short
 * reference analysis the prompt can use.
 */
class AgentMedia
{
    private const MAX_BYTES = 20 * 1024 * 1024;

    private const MAX_REFERENCES = 30;

    public function __construct(private readonly OpenRouterClient $ai)
    {
    }

    /**
     * @return array{text: string, analysis: ?array}
     */
    public function prepare(Artist $artist, string $projectId, string $text, string $tattooType): array
    {
        if (! str_contains($text, '[[REF_')) {
            return ['text' => $text, 'analysis' => null];
        }

        preg_match_all('/\[\[REF_FOTO:([^\]]+)\]\]/', $text, $photoMatches);
        preg_match_all('/\[\[REF_AUDIO:([^\]]+)\]\]/', $text, $audioMatches);

        $text = preg_replace('/\[\[REF_(?:FOTO|AUDIO):[^\]]+\]\]/', '', $text) ?? $text;

        $analysis = null;

        foreach (array_slice(array_unique($photoMatches[1] ?? []), 0, 3) as $url) {
            $stored = $this->storeImage($artist, $projectId, $url);

            if ($stored === null) {
                continue;
            }

            if ($analysis === null) {
                $analysis = $this->analyze($stored['body'], $stored['mime'], $tattooType);
                if ($analysis !== null) {
                    $this->saveAnalysis($projectId, $analysis, $stored['url']);
                }
            }
        }

        foreach (array_slice(array_unique($audioMatches[1] ?? []), 0, 2) as $url) {
            $transcript = $this->transcribe($url);

            $replacement = $transcript !== null && $transcript !== ''
                ? $transcript
                : '[O lead enviou um áudio que não pôde ser transcrito. Peça para repetir por texto.]';

            $marker = '[ÁUDIO RECEBIDO]';
            $position = strpos($text, $marker);

            if ($position !== false) {
                $text = substr_replace($text, $replacement, $position, strlen($marker));
            }
        }

        $text = trim(preg_replace("/[ \t]+\n/", "\n", $text) ?? $text);

        return ['text' => $text, 'analysis' => $analysis];
    }

    public function transcribe(string $url): ?string
    {
        $file = $this->download($url);

        if ($file === null || ! str_starts_with($file['mime'], 'audio/')) {
            return null;
        }

        $format = match (true) {
            str_contains($file['mime'], 'mpeg'), str_contains($file['mime'], 'mp3') => 'mp3',
            str_contains($file['mime'], 'mp4'), str_contains($file['mime'], 'm4a') => 'm4a',
            str_contains($file['mime'], 'wav') => 'wav',
            default => 'ogg',
        };

        try {
            $content = $this->ai->complete([
                ['role' => 'system', 'content' => 'Você transcreve áudios de clientes de um estúdio de tatuagem. Responda SOMENTE com a transcrição literal em português brasileiro, sem comentários, sem markdown, sem aspas e sem rótulos. Se não houver fala inteligível, responda com uma string vazia.'],
                ['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => 'Transcreva este áudio.'],
                    ['type' => 'input_audio', 'input_audio' => ['data' => base64_encode($file['body']), 'format' => $format]],
                ]],
            ], (string) config('agent.models.audio'), ['max_tokens' => 800]);
        } catch (\Throwable $e) {
            Log::warning('Audio transcription failed', ['error' => $e->getMessage()]);

            return null;
        }

        $content = trim($content, " \t\n\r\0\x0B\"'“”");

        return $content !== '' ? $content : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function analyze(string $body, string $mime, string $tattooType): ?array
    {
        $cover = in_array($tattooType, ['cover_up', 'rework'], true);
        $context = $cover
            ? ' ATENÇÃO: esta imagem é a TATUAGEM EXISTENTE que o cliente quer '.($tattooType === 'rework' ? 'reformar' : 'cobrir').'. Descreva-a como tatuagem atual a ser coberta, nunca como referência de desenho novo.'
            : '';

        $system = 'Você é um assistente de um estúdio de tatuagem. Analise a imagem que o cliente enviou: pode ser uma referência para uma tatuagem nova ou a tatuagem EXISTENTE que ele quer cobrir/reformar.
Responda SOMENTE com um objeto JSON válido, sem markdown, com estas chaves:
{
  "referencia_de_tatuagem": true ou false,
  "assunto": "2 a 5 palavras com o tema principal — SEMPRE preencha",
  "resumo": "1 frase curta, no máximo 120 caracteres — SEMPRE preencha",
  "estilo": "realismo | old_school | neo_tradicional | fine_line | new_school | blackwork | tradicional | geometrico | pontilhismo | aquarela | japones | oriental | tribal | floral | indefinido",
  "estilo_confianca": "alta | media | baixa",
  "cores": "preto e cinza | colorido | indefinido",
  "complexidade": "baixa | media | alta",
  "tipo": "desenho | foto_de_tatuagem | flash | outra",
  "local": "braco | braco_externo | braco_interno | antebraco | peito | costas | perna | coxa | panturrilha | tornozelo | pescoco | costela | barriga | ombro | pulso | dedo | mao | pe | indefinido",
  "tamanho": "pequeno | medio | grande | fechamento | indefinido",
  "cobertura": "sim | nao | talvez | indefinido",
  "confianca": "alta | media | baixa",
  "observacoes": "detalhes úteis, ou string vazia"
}
Se a imagem mostrar uma tatuagem no corpo, identifique o local e o tamanho aproximado. Não estime preço. Não invente detalhes que não dá para ver. NUNCA devolva assunto ou resumo vazios.';

        try {
            $content = $this->ai->complete([
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => 'Analise esta imagem enviada pelo cliente e devolva apenas o JSON pedido.'.$context],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$mime.';base64,'.base64_encode($body)]],
                ]],
            ], (string) config('agent.models.vision'), ['max_tokens' => 500]);
        } catch (\Throwable $e) {
            Log::warning('Reference analysis failed', ['error' => $e->getMessage()]);

            return null;
        }

        $data = Json::parse($content);
        if ($data === []) {
            return null;
        }

        $data['assunto'] = $data['assunto'] ?? ($data['resumo'] ?? 'imagem recebida');

        return $data;
    }

    /**
     * Describe media the artist sent, for calibration and the lead timeline.
     * Images become a short visual description; audio becomes a transcript.
     */
    public function describeArtistMedia(string $url, string $mime = ''): ?string
    {
        $file = $this->download($url);

        if ($file === null) {
            return null;
        }

        $mime = $file['mime'] !== '' ? $file['mime'] : $mime;

        if (str_starts_with($mime, 'audio/')) {
            $transcript = $this->transcribe($url);

            return $transcript !== null && $transcript !== ''
                ? 'Áudio do artista: '.$transcript
                : null;
        }

        if (! str_starts_with($mime, 'image/')) {
            return null;
        }

        $system = 'Você descreve o que o TATUADOR enviou no WhatsApp, para a equipe de calibração do atendimento.
Responda em português brasileiro, em 1 ou 2 frases curtas, sem markdown, sem aspas.

Diga o que é: foto de tatuagem, tabela de preços, desenho/referência, print de conversa, comprovante, foto do estúdio etc.
Se houver texto legível (preços, condições, horários, parcelas), transcreva os valores exatos.
Não invente. Se não der para identificar, responda "imagem sem detalhe legível".';

        try {
            $content = $this->ai->complete([
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => 'Descreva esta mídia enviada pelo tatuador.'],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$mime.';base64,'.base64_encode($file['body'])]],
                ]],
            ], (string) config('agent.models.vision'), ['max_tokens' => 300]);
        } catch (\Throwable $e) {
            Log::warning('Artist media description failed', ['error' => $e->getMessage()]);

            return null;
        }

        $content = trim($content, " \t\n\r\0\x0B\"'“”");

        return $content !== '' ? 'Imagem enviada pelo artista: '.$content : null;
    }

    /**
     * Append the image to the project and keep the stored URL.
     *
     * @return array{url: string, body: string, mime: string}|null
     */
    private function storeImage(Artist $artist, string $projectId, string $url): ?array
    {
        $file = $this->download($url);

        if ($file === null || ! str_starts_with($file['mime'], 'image/')) {
            return null;
        }

        $project = Project::query()->where('artist_id', $artist->id)->find($projectId);

        if (! $project) {
            return null;
        }

        $ext = match ($file['mime']) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'bin',
        };

        $path = 'references/'.$artist->id.'/'.$project->id.'/'.Str::uuid().'.'.$ext;
        Storage::disk('public')->put($path, $file['body']);
        $stored = Storage::disk('public')->url($path);

        DB::transaction(function () use ($project, $stored) {
            $locked = Project::query()->lockForUpdate()->find($project->id);
            $refs = $locked->reference_pics ?? [];

            if (! in_array($stored, $refs, true)) {
                $refs[] = $stored;
            }

            $locked->reference_pics = array_slice($refs, -self::MAX_REFERENCES);
            $locked->save();
        });

        return ['url' => $stored, 'body' => $file['body'], 'mime' => $file['mime']];
    }

    private function saveAnalysis(string $projectId, array $analysis, string $imageUrl): void
    {
        $project = Project::find($projectId);

        if (! $project) {
            return;
        }

        $analysis['image_url'] = $imageUrl;
        $analysis['analyzed_at'] = now()->toIso8601String();

        $project->reference_analysis = $analysis;
        $project->save();
    }

    /**
     * @return array{body: string, mime: string}|null
     */
    private function download(string $url): ?array
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';

        // Only WAHA-hosted media is fetched; a lead must not be able to make
        // the CRM request arbitrary internal URLs.
        if (! str_contains($path, '/api/files/')) {
            return null;
        }

        $target = rtrim((string) config('transport.waha_url'), '/').$path;

        try {
            $response = Http::withHeaders([
                'X-Api-Key' => (string) config('transport.waha_api_key'),
            ])->timeout(30)->get($target);
        } catch (\Throwable $e) {
            Log::warning('WAHA media download failed', ['url' => $target, 'error' => $e->getMessage()]);

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

        return ['body' => $body, 'mime' => $contentType !== '' ? $contentType : 'application/octet-stream'];
    }
}
