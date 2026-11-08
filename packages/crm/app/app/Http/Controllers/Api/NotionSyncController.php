<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sales\NotionPipelineImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * n8n → CRM Notion pipeline sync.
 *
 * The workflow owns the Notion property mapping and sends flat pages. Active
 * clients become Artists + `ganho` prospects (no contract), the rest become
 * backlog prospects.
 */
class NotionSyncController extends Controller
{
    public function __invoke(Request $request, NotionPipelineImporter $importer): JsonResponse
    {
        $payload = $request->all();
        $pages = $payload['pages'] ?? (array_is_list($payload) ? $payload : [$payload]);

        if (! is_array($pages) || $pages === []) {
            return response()->json([
                'ok' => false,
                'error' => 'envie {"pages": [...]} ou uma lista de páginas',
            ], 422);
        }

        $rules = [
            'notion_page_id' => ['nullable', 'string', 'max:64'],
            'name' => ['nullable', 'string', 'max:255'],
            'whatsapp' => ['nullable', 'string', 'max:64'],
            'instagram' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'max:255'],
            'status_contrato' => ['nullable', 'string', 'max:64'],
            'pipeline' => ['nullable', 'string', 'max:128'],
            'valor_fechamento' => ['nullable'],
            'inicio_contrato' => ['nullable', 'string', 'max:64'],
            'proximo_contato' => ['nullable', 'string', 'max:64'],
            'last_edited_time' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'string', 'max:2000'],
            'raw' => ['nullable', 'array'],
        ];

        $stats = [
            'processed' => 0,
            'created_client' => 0,
            'promoted_client' => 0,
            'updated_client' => 0,
            'created_backlog' => 0,
            'updated_backlog' => 0,
            'kept_active' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];
        $errors = [];

        foreach ($pages as $index => $page) {
            if (! is_array($page)) {
                $stats['failed']++;
                $errors[] = ['index' => $index, 'error' => 'página inválida'];

                continue;
            }

            $validator = Validator::make($page, $rules);

            if ($validator->fails()) {
                $stats['failed']++;
                $errors[] = [
                    'index' => $index,
                    'notion_page_id' => $page['notion_page_id'] ?? null,
                    'errors' => $validator->errors()->all(),
                ];

                continue;
            }

            try {
                $result = $importer->importRow($page);
            } catch (Throwable $e) {
                report($e);
                $stats['failed']++;
                $errors[] = [
                    'index' => $index,
                    'notion_page_id' => $page['notion_page_id'] ?? null,
                    'error' => 'falha ao importar',
                ];

                continue;
            }

            $stats['processed']++;

            if ($result['action'] === 'skip') {
                $stats['skipped']++;

                continue;
            }

            $stats[$result['action']] = ($stats[$result['action']] ?? 0) + 1;
        }

        return response()->json([
            'ok' => $stats['failed'] === 0,
            'stats' => $stats,
            'errors' => $errors,
        ]);
    }
}
