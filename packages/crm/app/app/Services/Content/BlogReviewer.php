<?php

namespace App\Services\Content;

use App\Services\Agent\Json;
use App\Services\Agent\OpenRouterClient;
use Illuminate\Support\Facades\Log;

/**
 * The AI copilot review shown above the article preview. It returns a short
 * admin overview, a score, checks and suggested fixes. It never publishes.
 */
class BlogReviewer
{
    public function __construct(private readonly OpenRouterClient $ai) {}

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public function review(array $draft, array $facts): array
    {
        $model = (string) config('content.review_model');
        $system = (string) file_get_contents(resource_path('prompts/blog-review.md'));

        $context = [
            'ARTISTA' => $facts,
            'ARTIGO' => [
                'title' => $draft['title'] ?? '',
                'slug' => $draft['slug'] ?? '',
                'meta_description' => $draft['meta_description'] ?? '',
                'target_keyword' => $draft['target_keyword'] ?? '',
                'category' => $draft['category'] ?? '',
                'tags' => $draft['tags'] ?? [],
                'faq' => $draft['faq'] ?? [],
                'body_md' => $draft['body_md'] ?? '',
            ],
            'POLÍTICA DE AFIRMAÇÕES' => [
                'permitido_sem_fonte' => [
                    'um dos principais tatuadores da região',
                    'referência em [especialidade] em [cidade]',
                    'reconhecido pelo cuidado com o acabamento',
                ],
                'exige_fonte_ou_ficha' => [
                    'anos de experiência',
                    'número de tatuagens',
                    'avaliação e depoimentos',
                    'preço, endereço, horários',
                    'especialidades e serviços',
                ],
                'proibido' => [
                    'o melhor do Brasil', 'nº 1', 'o único',
                    'prêmios ou certificações inventados',
                    'garantias médicas', 'depoimentos inventados',
                ],
            ],
        ];

        try {
            $content = $this->ai->complete(
                [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => Json::string($context)],
                ],
                $model,
                [
                    'temperature' => 0.1,
                    'max_tokens' => (int) config('content.review_max_tokens', 6000),
                    '_timeout' => (int) config('content.timeout', 150),
                ],
            );

            $parsed = Json::parse($content);
            if ($parsed === []) {
                throw new ContentException('O revisor não devolveu JSON válido.');
            }

            return $this->normalize($parsed, $model);
        } catch (\Throwable $e) {
            Log::warning('Blog copilot review failed', ['error' => $e->getMessage()]);

            return $this->fallback($model, $e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    private function normalize(array $parsed, string $model): array
    {
        $overview = trim((string) ($parsed['overview'] ?? $parsed['summary'] ?? ''));
        if ($overview === '') {
            $overview = 'O Copiloto não produziu um resumo legível. Leia o artigo e os avisos antes de aprovar.';
        }

        $score = (int) ($parsed['score'] ?? 0);
        $score = max(0, min(100, $score));

        $verdict = strtolower((string) ($parsed['verdict'] ?? 'review'));
        if (! in_array($verdict, ['approve', 'review', 'reject'], true)) {
            $verdict = 'review';
        }

        $checks = [];
        foreach ((array) ($parsed['checks'] ?? []) as $check) {
            if (! is_array($check)) {
                continue;
            }
            $name = trim((string) ($check['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $status = strtolower((string) ($check['status'] ?? 'warn'));
            if (! in_array($status, ['pass', 'warn', 'fail'], true)) {
                $status = 'warn';
            }
            $checks[] = [
                'name' => mb_substr($name, 0, 80),
                'status' => $status,
                'note' => mb_substr(trim((string) ($check['note'] ?? '')), 0, 400),
            ];
        }

        $issues = [];
        foreach ((array) ($parsed['issues'] ?? []) as $issue) {
            if (! is_array($issue)) {
                continue;
            }
            $title = trim((string) ($issue['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $severity = strtolower((string) ($issue['severity'] ?? 'info'));
            if (! in_array($severity, ['info', 'warning', 'critical'], true)) {
                $severity = 'info';
            }
            $issues[] = [
                'severity' => $severity,
                'title' => mb_substr($title, 0, 120),
                'body' => mb_substr(trim((string) ($issue['body'] ?? '')), 0, 700),
                'suggested_fix' => mb_substr(trim((string) ($issue['suggested_fix'] ?? '')), 0, 500),
            ];
        }

        $riskFlags = [];
        foreach ((array) ($parsed['risk_flags'] ?? []) as $flag) {
            if (is_string($flag) && trim($flag) !== '') {
                $riskFlags[] = mb_substr(trim($flag), 0, 160);
            }
        }

        $edits = [];
        foreach ((array) ($parsed['suggested_edits'] ?? []) as $edit) {
            if (! is_array($edit)) {
                continue;
            }
            $find = trim((string) ($edit['find'] ?? ''));
            $replace = trim((string) ($edit['replace'] ?? ''));
            if ($find === '' || $replace === '') {
                continue;
            }
            $edits[] = [
                'find' => mb_substr($find, 0, 300),
                'replace' => mb_substr($replace, 0, 300),
                'reason' => mb_substr(trim((string) ($edit['reason'] ?? '')), 0, 300),
            ];
        }

        return [
            'overview' => mb_substr($overview, 0, 1200),
            'score' => $score,
            'verdict' => $verdict,
            'checks' => array_slice($checks, 0, 8),
            'issues' => array_slice($issues, 0, 8),
            'risk_flags' => array_slice($riskFlags, 0, 6),
            'suggested_edits' => array_slice($edits, 0, 5),
            'model' => $model,
            'error' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fallback(string $model, string $error): array
    {
        return [
            'overview' => 'A revisão automática não pôde ser concluída. O artigo está disponível para leitura manual; confira fatos, links e tom antes de aprovar.',
            'score' => 0,
            'verdict' => 'review',
            'checks' => [],
            'issues' => [[
                'severity' => 'warning',
                'title' => 'Revisão automática indisponível',
                'body' => 'O Copiloto não conseguiu analisar este artigo agora.',
                'suggested_fix' => 'Leia o artigo manualmente ou use "Regenerar com comentários".',
            ]],
            'risk_flags' => [],
            'suggested_edits' => [],
            'model' => $model,
            'error' => true,
            'error_message' => mb_substr($error, 0, 300),
        ];
    }
}
