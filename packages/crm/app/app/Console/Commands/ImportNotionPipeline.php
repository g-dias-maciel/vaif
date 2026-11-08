<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Sales\NotionPipelineImporter;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * One-off (and re-runnable) import of the Notion pipeline database export.
 *
 * Active clients (`Status Contrato = Ativo`) become Artists + `ganho`
 * prospects. Every other page becomes a backlog prospect with flags. The
 * import never creates contracts, logins or invites.
 */
class ImportNotionPipeline extends Command
{
    protected $signature = 'sales:import-notion
        {file? : Caminho do CSV exportado do Notion}
        {--dry-run : Lê e relata sem gravar}
        {--owner= : E-mail do usuário sales/staff que será dono dos novos prospects}
        {--report= : Caminho do relatório JSON (por padrão não grava arquivo)}
        {--limit= : Importa no máximo N linhas canônicas}';

    protected $description = 'Importa o pipeline do Notion: clientes ativos como Artist + prospect ganho, o resto como backlog';

    private const MERGE_FIELDS = [
        'Name',
        'Email',
        'Instagram',
        'Valor de Fechamento',
        'Inicio Contrato',
        'Proximo Contato',
        'Endereço',
        'CPF',
        'CNPJ',
        'Last edited time',
        'Nome Contrato',
        'Link Form Contrato',
        'Pagamentos',
        'Service',
        'Atendente',
        'Contato',
        'Interesse',
        'Nivel',
    ];

    public function handle(NotionPipelineImporter $importer): int
    {
        $file = (string) ($this->argument('file') ?: base_path('../../../Pipeline 605d9785b9b14dfe9ac8afeaf665c5b3_all.csv'));

        if (! is_file($file)) {
            $this->error("Arquivo não encontrado: {$file}");

            return self::FAILURE;
        }

        $ownerId = $this->resolveOwner();

        if ($ownerId === false) {
            return self::FAILURE;
        }

        try {
            $rows = $this->readCsv($file);
        } catch (Throwable $e) {
            $this->error('Não foi possível ler o CSV: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($rows === []) {
            $this->error('Nenhuma linha encontrada no CSV.');

            return self::FAILURE;
        }

        $stats = [
            'rows_read' => count($rows),
            'skipped_invalid_phone' => 0,
            'distinct_phones' => 0,
            'duplicates_merged' => 0,
            'created_client' => 0,
            'promoted_client' => 0,
            'updated_client' => 0,
            'created_backlog' => 0,
            'updated_backlog' => 0,
            'kept_active' => 0,
            'created_artist' => 0,
            'linked_artist' => 0,
            'failed' => 0,
        ];
        $flagCounts = [];
        $skipped = [];
        $groups = [];

        foreach ($rows as $row) {
            $phone = NotionPipelineImporter::normalizePhone($row['Telefone'] ?? null);

            if ($phone === null) {
                $stats['skipped_invalid_phone']++;
                $skipped[] = [
                    'name' => $row['Name'] ?? null,
                    'instagram' => $row['Instagram'] ?? null,
                    'telefone' => $row['Telefone'] ?? null,
                    'pipeline' => $row['Pipeline'] ?? null,
                    'status_contrato' => $row['Status Contrato'] ?? null,
                    'reason' => 'telefone_invalido',
                ];

                continue;
            }

            $groups[$phone][] = $row;
        }

        $stats['distinct_phones'] = count($groups);
        $canonical = [];

        foreach ($groups as $phone => $group) {
            if (count($group) > 1) {
                $stats['duplicates_merged'] += count($group) - 1;
            }

            $best = $this->pickCanonical($group);
            $best['_merged_count'] = count($group);
            $best['_phone'] = $phone;
            $canonical[] = $best;
        }

        // Clients first so the operator sees the most important rows early.
        usort($canonical, function (array $a, array $b): int {
            $aActive = $this->isActive($a) ? 0 : 1;
            $bActive = $this->isActive($b) ? 0 : 1;

            return $aActive <=> $bActive ?: strcmp((string) ($a['Name'] ?? ''), (string) ($b['Name'] ?? ''));
        });

        if ($limit = (int) $this->option('limit')) {
            $canonical = array_slice($canonical, 0, max(0, $limit));
        }

        $dryRun = (bool) $this->option('dry-run');
        $bar = $this->output->createProgressBar(count($canonical));
        $bar->start();

        foreach ($canonical as $row) {
            $result = $importer->importRow($this->toImportRow($row), $dryRun, $ownerId);
            $bar->advance();

            if ($result['action'] === 'skip') {
                $stats['skipped_invalid_phone']++;
                $skipped[] = [
                    'name' => $row['Name'] ?? null,
                    'instagram' => $row['Instagram'] ?? null,
                    'telefone' => $row['Telefone'] ?? null,
                    'reason' => $result['reason'] ?? 'telefone_invalido',
                ];

                continue;
            }

            $stats[$result['action']] = ($stats[$result['action']] ?? 0) + 1;

            if (($result['artist_action'] ?? null) === 'created') {
                $stats['created_artist']++;
            } elseif (($result['artist_action'] ?? null) === 'linked') {
                $stats['linked_artist']++;
            }

            foreach ($result['flags'] as $flag) {
                $flagCounts[$flag] = ($flagCounts[$flag] ?? 0) + 1;
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->report($stats, $flagCounts, $skipped, $file, $dryRun);

        $reportPath = $this->option('report');

        if ($reportPath) {
            $this->writeReport($reportPath, $stats, $flagCounts, $skipped, $file, $dryRun);
            $this->line('Relatório: '.$reportPath);
        }

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function readCsv(string $file): array
    {
        $handle = fopen($file, 'r');

        if ($handle === false) {
            throw new \RuntimeException('fopen falhou');
        }

        $header = fgetcsv($handle, 0, ',', '"', '\\');

        if ($header === false) {
            fclose($handle);

            return [];
        }

        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $rows = [];

        while (($line = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if ($line === [null] || $line === []) {
                continue;
            }

            $row = [];

            foreach ($header as $index => $column) {
                $row[$column] = $line[$index] ?? '';
            }

            if (trim(implode('', $row)) === '') {
                continue;
            }

            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param  array<int, array<string, string>>  $group
     * @return array<string, string>
     */
    private function pickCanonical(array $group): array
    {
        usort($group, function (array $a, array $b): int {
            $active = (int) $this->isActive($b) <=> (int) $this->isActive($a);

            if ($active !== 0) {
                return $active;
            }

            $completeness = $this->completeness($b) <=> $this->completeness($a);

            if ($completeness !== 0) {
                return $completeness;
            }

            return $this->editedAt($b)?->timestamp <=> $this->editedAt($a)?->timestamp;
        });

        $best = $group[0];

        foreach (array_slice($group, 1) as $other) {
            foreach (self::MERGE_FIELDS as $field) {
                if (blank($best[$field] ?? null) && filled($other[$field] ?? null)) {
                    $best[$field] = $other[$field];
                }
            }
        }

        return $best;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function isActive(array $row): bool
    {
        return mb_strtolower(trim((string) ($row['Status Contrato'] ?? ''))) === 'ativo';
    }

    /**
     * @param  array<string, string>  $row
     */
    private function completeness(array $row): int
    {
        $keys = [
            'Name', 'Email', 'Instagram', 'Valor de Fechamento', 'Inicio Contrato',
            'Proximo Contato', 'Endereço', 'CPF', 'CNPJ', 'Nome Contrato',
        ];
        $score = 0;

        foreach ($keys as $key) {
            if (filled($row[$key] ?? null)) {
                $score++;
            }
        }

        return $score;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function editedAt(array $row): ?Carbon
    {
        $value = trim((string) ($row['Last edited time'] ?? ''));

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private function toImportRow(array $row): array
    {
        $mergedCount = (int) ($row['_merged_count'] ?? 1);
        unset($row['_merged_count'], $row['_phone']);

        return [
            'notion_page_id' => null,
            'name' => $row['Name'] ?? '',
            'whatsapp' => $row['Telefone'] ?? '',
            'instagram' => $row['Instagram'] ?? '',
            'email' => $row['Email'] ?? '',
            'status_contrato' => $row['Status Contrato'] ?? '',
            'pipeline' => $row['Pipeline'] ?? '',
            'valor_fechamento' => $row['Valor de Fechamento'] ?? '',
            'inicio_contrato' => $row['Inicio Contrato'] ?? '',
            'proximo_contato' => $row['Proximo Contato'] ?? '',
            'last_edited_time' => $row['Last edited time'] ?? '',
            'address' => $row['Endereço'] ?? '',
            'merged_count' => $mergedCount,
            'raw' => $row,
        ];
    }

    private function resolveOwner(): int|false|null
    {
        $email = $this->option('owner');

        if (! $email) {
            return null;
        }

        $user = User::where('email', mb_strtolower(trim((string) $email)))->first();

        if (! $user || (! $user->isSales() && ! $user->isStaff())) {
            $this->error("Owner inválido: {$email}. Use um login sales ou staff.");

            return false;
        }

        return $user->id;
    }

    /**
     * @param  array<string, int>  $stats
     * @param  array<string, int>  $flags
     * @param  array<int, array<string, mixed>>  $skipped
     */
    private function report(array $stats, array $flags, array $skipped, string $file, bool $dryRun): void
    {
        $this->line($dryRun ? '<comment>Modo dry-run: nada foi gravado.</comment>' : '<info>Importação concluída.</info>');
        $this->line('Arquivo: '.$file);

        $rows = [
            ['Linhas lidas', $stats['rows_read']],
            ['Telefones distintos', $stats['distinct_phones']],
            ['Duplicados mesclados', $stats['duplicates_merged']],
            ['Telefone inválido (ignorados)', $stats['skipped_invalid_phone']],
            ['Clientes criados', $stats['created_client']],
            ['Clientes promovidos do backlog', $stats['promoted_client']],
            ['Clientes atualizados', $stats['updated_client']],
            ['Backlog criado', $stats['created_backlog']],
            ['Backlog atualizado', $stats['updated_backlog']],
            ['Prospect ativo preservado', $stats['kept_active']],
            ['Artists criados', $stats['created_artist']],
            ['Artists existentes vinculados', $stats['linked_artist']],
            ['Falhas', $stats['failed']],
        ];

        $this->table(['Métrica', 'Total'], $rows);

        if ($flags !== []) {
            arsort($flags);
            $this->table(
                ['Flag', 'Linhas'],
                array_map(fn (string $flag, int $count): array => [$flag, $count], array_keys($flags), array_values($flags)),
            );
        }

        if ($skipped !== []) {
            $this->warn(count($skipped).' linha(s) ignorada(s) por telefone inválido; use --report para ver os detalhes.');
        }
    }

    /**
     * @param  array<string, int>  $stats
     * @param  array<string, int>  $flags
     * @param  array<int, array<string, mixed>>  $skipped
     */
    private function writeReport(
        string $path,
        array $stats,
        array $flags,
        array $skipped,
        string $file,
        bool $dryRun,
    ): void {
        $payload = [
            'generated_at' => now()->toIso8601String(),
            'file' => $file,
            'dry_run' => $dryRun,
            'stats' => $stats,
            'flags' => $flags,
            'skipped' => $skipped,
        ];

        $directory = dirname($path);

        if (! is_dir($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        file_put_contents(
            $path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }
}
