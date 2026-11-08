<?php

namespace App\Console\Commands;

use App\Models\Prospect;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Imports the calculator leads (LP MySQL `leads` dump) into the internal
 * prospects pipeline as a backlog.
 *
 * The import is idempotent: it matches by normalized WhatsApp, then by
 * legacy_id, and never overwrites the sales state (status, stage, owner,
 * notes, next action) of an existing prospect.
 */
class ImportLeads extends Command
{
    protected $signature = 'sales:import-leads
        {--file= : Caminho do dump MySQL (padrão: ../../../leads.sql)}
        {--dry-run : Lê e relata sem gravar}
        {--limit= : Importa no máximo N prospects, por receita decrescente}
        {--owner= : E-mail do usuário sales/staff que será dono dos novos prospects}';

    protected $description = 'Importa os leads da calculadora (dump MySQL) para o pipeline interno de prospects';

    private const BANDS = [
        Prospect::BAND_FULL_SERVICE,
        Prospect::BAND_RETAINER,
        Prospect::BAND_ENTRY,
        Prospect::BAND_NURTURE,
        Prospect::BAND_UNKNOWN,
    ];

    public function handle(): int
    {
        $file = (string) ($this->option('file') ?: base_path('../../../leads.sql'));

        if (! is_file($file)) {
            $this->error("Arquivo não encontrado: {$file}");

            return self::FAILURE;
        }

        try {
            $rows = $this->parseDump($file);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($rows === []) {
            $this->error('Nenhuma linha encontrada no dump.');

            return self::FAILURE;
        }

        $ownerId = $this->resolveOwner();

        if ($ownerId === false) {
            return self::FAILURE;
        }

        $stats = [
            'read' => count($rows),
            'test' => 0,
            'invalid_phone' => 0,
            'duplicates' => 0,
            'created' => 0,
            'updated' => 0,
            'failed' => 0,
        ];
        $skipped = [];
        $groups = [];

        foreach ($rows as $row) {
            if ($this->isTest($row)) {
                $stats['test']++;

                continue;
            }

            $phone = $this->normalizePhone($row['whatsapp'] ?? null);

            if ($phone === null) {
                $stats['invalid_phone']++;
                $skipped[] = ['id' => $row['id'] ?? '?', 'reason' => 'telefone inválido'];

                continue;
            }

            $groups[$phone][] = $row;
        }

        $canonical = [];

        foreach ($groups as $phone => $group) {
            if (count($group) > 1) {
                $stats['duplicates'] += count($group) - 1;
            }

            $best = $this->pickCanonical($group);
            $best['_merged_count'] = count($group);
            $canonical[] = $best;
        }

        usort($canonical, fn (array $a, array $b): int => $this->revenueFor($b)[0] <=> $this->revenueFor($a)[0]);

        if ($limit = (int) $this->option('limit')) {
            $canonical = array_slice($canonical, 0, max(0, $limit));
        }

        $bandCounts = array_fill_keys(self::BANDS, 0);
        $flagCounts = [];
        $prepared = [];

        foreach ($canonical as $row) {
            [$revenue, $revenueFlags] = $this->revenueFor($row);
            [$ticket, $ticketFlags] = $this->ticketFor($row);
            [$sessions, $sessionsFlags] = $this->sessionsFor($row);
            $hours = $this->toFloat($row['horas_admin'] ?? null);
            $revenueCents = $revenue !== null ? (int) round($revenue * 100) : null;
            $band = Prospect::bandForCents($revenueCents);

            $flags = array_values(array_unique(array_merge(
                $this->flagsFor($row),
                $revenueFlags,
                $ticketFlags,
                $sessionsFlags,
                ($row['_merged_count'] ?? 1) > 1 ? ['duplicado_mesclado'] : [],
            )));

            $bandCounts[$band]++;

            foreach ($flags as $flag) {
                $flagCounts[$flag] = ($flagCounts[$flag] ?? 0) + 1;
            }

            $prepared[] = [
                'row' => $row,
                'phone' => $this->normalizePhone($row['whatsapp'] ?? null),
                'revenueCents' => $revenueCents,
                'ticketCents' => $ticket !== null ? (int) round($ticket * 100) : null,
                'sessions' => $sessions,
                'hours' => $hours,
                'flags' => $flags,
                'band' => $band,
            ];
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->report($stats, $bandCounts, $flagCounts, $prepared, $skipped, true);

            return self::SUCCESS;
        }

        DB::beginTransaction();

        try {
            foreach ($prepared as $item) {
                try {
                    $this->importOne($item, $ownerId, $stats);
                } catch (Throwable $e) {
                    $stats['failed']++;
                    $skipped[] = ['id' => $item['row']['id'] ?? '?', 'reason' => $e->getMessage()];
                }
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error('Falha na importação: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->report($stats, $bandCounts, $flagCounts, $prepared, $skipped, false);

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array{row: array, phone: string, revenueCents: ?int, ticketCents: ?int, sessions: ?int, hours: ?float, flags: array, band: string}  $item
     * @param  array<string, int>  $stats
     */
    private function importOne(array $item, ?int $ownerId, array &$stats): void
    {
        $row = $item['row'];
        $phone = $item['phone'];
        $legacyId = isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : null;

        $prospect = Prospect::where('whatsapp_e164', $phone)->first();

        if (! $prospect && $legacyId !== null) {
            $prospect = Prospect::where('legacy_id', $legacyId)->first();
        }

        $isNew = $prospect === null;

        if ($isNew) {
            $prospect = new Prospect;
            $prospect->status = Prospect::STATUS_BACKLOG;
            $prospect->source = 'calculadora';
        }

        $raw = $row;
        unset($raw['_merged_count']);

        $prospect->fill([
            'name' => $this->cleanName($row['nome'] ?? ''),
            'whatsapp_e164' => $phone,
            'whatsapp_raw' => $this->cleanString($row['whatsapp'] ?? null),
            'instagram' => $this->cleanInstagram($row['instagram'] ?? null),
            'revenue_monthly_cents' => $item['revenueCents'],
            'ticket_cents' => $item['ticketCents'],
            'sessions_monthly' => $item['sessions'],
            'admin_hours_day' => $item['hours'],
            'hidden_loss_cents' => $this->toCents($row['prejuizo_mensal'] ?? null),
            'potential_revenue_cents' => $this->toCents($row['potencial_lucro'] ?? null),
            'legacy_id' => $legacyId,
            'legacy_notion_id' => $this->cleanString($row['notion_id'] ?? null),
            'import_flags' => $item['flags'],
            'raw_payload' => $raw,
        ]);

        if ($isNew) {
            $prospect->created_at = $this->toDate($row['created_at'] ?? null) ?? now();

            if ($ownerId !== null) {
                $prospect->owner_id = $ownerId;
            }
        } elseif ($ownerId !== null && $prospect->owner_id === null) {
            $prospect->owner_id = $ownerId;
        }

        if ($prospect->booked_call_at === null && ($booked = $this->toDate($row['data_agendamento'] ?? null))) {
            $prospect->booked_call_at = $booked;
        }

        $prospect->save();

        $isNew ? $stats['created']++ : $stats['updated']++;
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
     * Parse the single INSERT statement from the phpMyAdmin dump using an
     * in-memory SQLite database, so quoting and NULLs are handled correctly.
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseDump(string $path): array
    {
        $sql = file_get_contents($path);

        if ($sql === false) {
            throw new RuntimeException("Não foi possível ler {$path}.");
        }

        if (! preg_match('/INSERT INTO `leads`\s*\(([^)]+)\)\s*VALUES\s*(.*?);\s*(?:--|\z)/s', $sql, $match)) {
            throw new RuntimeException('INSERT INTO `leads` não encontrado no dump.');
        }

        $columns = array_map(
            fn (string $column): string => trim($column, " `\n\r\t"),
            explode(',', $match[1]),
        );

        $values = str_replace("\\'", "''", $match[2]);

        $types = [
            'id' => 'INTEGER',
            'faturamento' => 'REAL',
            'ticket' => 'REAL',
            'sessoes' => 'INTEGER',
            'horas_admin' => 'REAL',
            'valor_hora' => 'REAL',
            'horas_secretario' => 'REAL',
            'prejuizo_mensal' => 'REAL',
            'potencial_lucro' => 'REAL',
            'processado' => 'INTEGER',
        ];

        $definitions = array_map(
            fn (string $column): string => '"'.$column.'" '.($types[$column] ?? 'TEXT'),
            $columns,
        );

        $quoted = array_map(fn (string $column): string => '"'.$column.'"', $columns);

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE leads ('.implode(', ', $definitions).')');
        $pdo->exec('INSERT INTO leads ('.implode(', ', $quoted).') VALUES '.$values);

        return $pdo->query('SELECT * FROM leads')->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param  array<int, array<string, mixed>>  $group
     * @return array<string, mixed>
     */
    private function pickCanonical(array $group): array
    {
        usort($group, function (array $a, array $b): int {
            $completeness = $this->completeness($b) <=> $this->completeness($a);

            if ($completeness !== 0) {
                return $completeness;
            }

            return ($this->toDate($b['created_at'] ?? null)?->timestamp ?? 0)
                <=> ($this->toDate($a['created_at'] ?? null)?->timestamp ?? 0);
        });

        $best = $group[0];

        // Fill gaps from the duplicates without replacing the chosen row.
        $mergeKeys = [
            'nome', 'instagram', 'faturamento', 'ticket', 'sessoes',
            'horas_admin', 'prejuizo_mensal', 'potencial_lucro',
            'notion_id', 'data_agendamento',
        ];

        foreach (array_slice($group, 1) as $other) {
            foreach ($mergeKeys as $key) {
                if (blank($best[$key] ?? null) && filled($other[$key] ?? null)) {
                    $best[$key] = $other[$key];
                }
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function completeness(array $row): int
    {
        $keys = [
            'nome', 'whatsapp', 'instagram', 'faturamento', 'ticket',
            'sessoes', 'horas_admin', 'prejuizo_mensal', 'potencial_lucro',
        ];

        $score = 0;

        foreach ($keys as $key) {
            if (filled($row[$key] ?? null)) {
                $score++;
            }
        }

        $name = (string) ($row['nome'] ?? '');

        if (str_contains($name, '@') && ! str_contains($name, ' ')) {
            $score -= 2;
        }

        return $score;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{0: ?float, 1: array<int, string>}
     */
    private function revenueFor(array $row): array
    {
        $revenue = $this->toFloat($row['faturamento'] ?? null);
        $ticket = $this->toFloat($row['ticket'] ?? null);
        $flags = [];

        // The form sent "8.000" and MySQL stored 8.00 (thousands separator lost).
        if ($revenue !== null && $revenue > 0 && $revenue < 100 && $ticket !== null && $ticket >= 100) {
            $revenue *= 1000;
            $flags[] = 'receita_corrigida';
        }

        if ($revenue !== null && $revenue <= 0) {
            $revenue = null;
        }

        return [$revenue, $flags];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{0: ?float, 1: array<int, string>}
     */
    private function ticketFor(array $row): array
    {
        $ticket = $this->toFloat($row['ticket'] ?? null);
        $flags = [];

        if ($ticket !== null && ($ticket <= 0 || $ticket > 10_000)) {
            $flags[] = 'ticket_outlier';
            $ticket = null;
        }

        return [$ticket, $flags];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{0: ?int, 1: array<int, string>}
     */
    private function sessionsFor(array $row): array
    {
        $sessions = $this->toInt($row['sessoes'] ?? null);
        $flags = [];

        if ($sessions !== null && ($sessions <= 0 || $sessions > 60)) {
            $flags[] = 'sessoes_outlier';
            $sessions = null;
        }

        return [$sessions, $flags];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, string>
     */
    private function flagsFor(array $row): array
    {
        $flags = [];
        $revenue = $this->toFloat($row['faturamento'] ?? null);
        $hours = $this->toFloat($row['horas_admin'] ?? null);
        $loss = $this->toFloat($row['prejuizo_mensal'] ?? null);
        $name = (string) ($row['nome'] ?? '');

        if ($revenue !== null && $revenue > 0 && $revenue < 100) {
            $flags[] = 'receita_suspeita_baixa';
        }

        if ($hours !== null && $hours > 16) {
            $flags[] = 'horas_admin_outlier';
        }

        if ($loss !== null && $loss < 0) {
            $flags[] = 'prejuizo_negativo';
        }

        if (str_contains($name, '@') && ! str_contains($name, ' ')) {
            $flags[] = 'nome_e_email';
        }

        return $flags;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isTest(array $row): bool
    {
        $name = mb_strtolower(trim((string) ($row['nome'] ?? '')));
        $instagram = mb_strtolower(trim((string) ($row['instagram'] ?? '')));
        $digits = preg_replace('/\D+/', '', (string) ($row['whatsapp'] ?? '')) ?? '';

        return str_contains($name, 'teste')
            || in_array($instagram, ['teste', '111111'], true)
            || $digits === '11111111111'
            || str_starts_with($digits, '111111');
    }

    private function normalizePhone(mixed $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw) ?? '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '55') && strlen($digits) > 11) {
            return strlen($digits) <= 13 ? '+'.$digits : null;
        }

        if (strlen($digits) === 10 || strlen($digits) === 11) {
            return '+55'.$digits;
        }

        return null;
    }

    private function cleanName(mixed $value): string
    {
        $name = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');

        return $name !== '' ? $name : 'Sem nome';
    }

    private function cleanInstagram(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (str_contains($value, 'instagram.com/')) {
            $value = parse_url($value, PHP_URL_PATH) ?: $value;
            $value = trim((string) $value, '/');
        }

        return ltrim($value, '@') ?: null;
    }

    private function cleanString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        $clean = preg_replace('/[^0-9,.\-]/', '', (string) $value) ?? '';

        if (str_contains($clean, ',')) {
            $clean = str_replace(['.', ','], ['', '.'], $clean);
        }

        return is_numeric($clean) ? (float) $clean : null;
    }

    private function toInt(mixed $value): ?int
    {
        $float = $this->toFloat($value);

        return $float === null ? null : (int) round($float);
    }

    private function toCents(mixed $value): ?int
    {
        $float = $this->toFloat($value);

        return $float === null ? null : (int) round($float * 100);
    }

    private function toDate(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }

    private function maskPhone(?string $phone): string
    {
        if ($phone === null || strlen($phone) < 8) {
            return '—';
        }

        return substr($phone, 0, 5).'****'.substr($phone, -3);
    }

    /**
     * @param  array<string, int>  $stats
     * @param  array<string, int>  $bandCounts
     * @param  array<string, int>  $flagCounts
     * @param  array<int, array<string, mixed>>  $prepared
     * @param  array<int, array{id: mixed, reason: string}>  $skipped
     */
    private function report(
        array $stats,
        array $bandCounts,
        array $flagCounts,
        array $prepared,
        array $skipped,
        bool $dryRun,
    ): void {
        $this->info($dryRun ? 'DRY RUN — nenhum registro foi gravado.' : 'Importação concluída.');
        $this->newLine();

        $this->table(['Métrica', 'Valor'], [
            ['Linhas lidas', $stats['read']],
            ['Testes ignorados', $stats['test']],
            ['Telefones inválidos', $stats['invalid_phone']],
            ['Duplicatas mescladas', $stats['duplicates']],
            ['Prospects válidos', count($prepared)],
            ['Criados', $dryRun ? '—' : $stats['created']],
            ['Atualizados', $dryRun ? '—' : $stats['updated']],
            ['Falhas', $dryRun ? '—' : $stats['failed']],
        ]);

        $this->table(
            ['Banda', 'Prospects'],
            collect($bandCounts)->map(fn (int $count, string $band): array => [$band, $count])->values()->all(),
        );

        if ($flagCounts !== []) {
            arsort($flagCounts);
            $this->table(
                ['Flag', 'Ocorrências'],
                collect($flagCounts)->map(fn (int $count, string $flag): array => [$flag, $count])->values()->all(),
            );
        }

        $top = collect($prepared)->take(10)->map(fn (array $item): array => [
            $item['row']['id'] ?? '—',
            mb_strimwidth((string) ($item['row']['nome'] ?? '—'), 0, 28, '…'),
            $this->maskPhone($item['phone']),
            $item['revenueCents'] !== null ? 'R$ '.number_format($item['revenueCents'] / 100, 0, ',', '.') : '—',
            $item['band'],
            implode(', ', $item['flags']) ?: '—',
        ]);

        $this->table(['ID', 'Nome', 'Telefone', 'Receita', 'Banda', 'Flags'], $top->all());

        if ($skipped !== []) {
            $this->warn('Linhas ignoradas ou com falha:');

            foreach (array_slice($skipped, 0, 20) as $skip) {
                $this->line("  #{$skip['id']} — {$skip['reason']}");
            }

            if (count($skipped) > 20) {
                $this->line('  … e mais '.(count($skipped) - 20).' linha(s).');
            }
        }
    }
}
