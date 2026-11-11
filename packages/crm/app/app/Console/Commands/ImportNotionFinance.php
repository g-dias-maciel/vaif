<?php

namespace App\Console\Commands;

use App\Models\BillingExpense;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\Prospect;
use App\Models\User;
use App\Services\Finance\InvoiceNumberGenerator;
use App\Services\Finance\PaymentRecorder;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Imports the normalized Notion Financeiro export.
 *
 * Entradas → invoices plus (when paid) linked payments. Card entries are
 * imported as historical invoices too, so billed totals match Notion.
 * Saídas → paid expenses assigned to the competence month.
 *
 * Idempotent: invoices/payments use external_ref = notion:<page_id>, expenses
 * use notion_page_id.
 */
class ImportNotionFinance extends Command
{
    protected $signature = 'finance:import-notion
        {file : Caminho do JSON normalizado (Overview, Entradas, Saidas, Clients)}
        {--dry-run : Lê e relata sem gravar}
        {--limit= : Limita entradas e saídas processadas}
        {--report= : Caminho do relatório JSON}';

    protected $description = 'Importa Entradas e Saídas do Notion Financeiro para o CRM';

    private const MONTHS = [
        'janeiro' => 1, 'fevereiro' => 2, 'marco' => 3, 'abril' => 4,
        'maio' => 5, 'junho' => 6, 'julho' => 7, 'agosto' => 8,
        'setembro' => 9, 'outubro' => 10, 'novembro' => 11, 'dezembro' => 12,
    ];

    /** @var array<string, Prospect> */
    private array $prospectsByLegacy = [];

    /** @var array<string, Prospect> */
    private array $prospectsByPhone = [];

    /** @var array<string, Prospect> */
    private array $prospectsByEmail = [];

    /** @var array<string, Prospect> */
    private array $prospectsByName = [];

    /** @var array<string, Prospect> */
    private array $prospectsByInstagram = [];

    /** @var array<string, int> */
    private array $usersByName = [];

    /** @var array<string, Carbon> */
    private array $monthsByPageId = [];

    /** @var array<string, bool> */
    private array $resolvedClientIds = [];

    private int $placeholderSequence = 1;

    /** @var array<string, int> */
    private array $stats = [];

    /** @var array<int, array<string, mixed>> */
    private array $skipped = [];

    public function handle(
        InvoiceNumberGenerator $numbers,
        PaymentRecorder $payments,
    ): int {
        $file = (string) $this->argument('file');

        if (! is_file($file)) {
            $this->error("Arquivo não encontrado: {$file}");

            return self::FAILURE;
        }

        $payload = json_decode((string) file_get_contents($file), true);

        if (! is_array($payload) || ! isset($payload['Entradas'], $payload['Saidas'])) {
            $this->error('JSON inválido: esperado Overview, Entradas, Saidas e Clients.');

            return self::FAILURE;
        }

        $this->stats = [
            'clients_matched' => 0,
            'clients_created' => 0,
            'clients_updated' => 0,
            'invoices_created' => 0,
            'invoices_existing' => 0,
            'payments_created' => 0,
            'payments_existing' => 0,
            'expenses_created' => 0,
            'expenses_existing' => 0,
            'skipped_no_amount' => 0,
            'skipped_no_month' => 0,
            'skipped_no_client' => 0,
        ];
        $this->skipped = [];

        $this->loadProspects();
        $this->loadUsers();
        $this->loadMonths($payload['Overview'] ?? []);

        $entradas = $payload['Entradas'];
        $saidas = $payload['Saidas'];

        if ($limit = (int) $this->option('limit')) {
            $entradas = array_slice($entradas, 0, $limit);
            $saidas = array_slice($saidas, 0, $limit);
        }

        $dryRun = (bool) $this->option('dry-run');
        $clients = $payload['Clients'] ?? [];
        $people = $payload['People'] ?? [];

        DB::beginTransaction();

        try {
            foreach ($entradas as $row) {
                $this->importEntrada($row, $clients, $numbers, $payments);
            }

            foreach ($saidas as $row) {
                $this->importSaida($row, $people);
            }

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error('Falha na importação: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->report($dryRun);

        if ($reportPath = $this->option('report')) {
            $this->writeReport($reportPath, $dryRun);
            $this->line('Relatório: '.$reportPath);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $clients
     */
    private function importEntrada(
        array $row,
        array $clients,
        InvoiceNumberGenerator $numbers,
        PaymentRecorder $payments,
    ): void {
        $pageId = (string) ($row['_page_id'] ?? '');
        $amountCents = $this->toCents($row['Valor Final'] ?? null);

        if ($amountCents === null || $amountCents <= 0) {
            $this->skip('entrada', $pageId, 'sem_valor', $row['Pagamento'] ?? null);
            $this->stats['skipped_no_amount']++;

            return;
        }

        $clientIds = $row['Cliente'] ?? [];
        $clientId = is_array($clientIds) ? (string) ($clientIds[0] ?? '') : '';
        $prospect = $this->resolveClient($clientId, $clients, $row);

        if ($prospect === null) {
            $this->skip('entrada', $pageId, 'sem_cliente', $row['Pagamento'] ?? null);
            $this->stats['skipped_no_client']++;

            return;
        }

        $month = $this->monthFor($row);
        $dataDate = ! empty($row['Data']) ? Carbon::parse((string) $row['Data']) : null;
        $month ??= $dataDate?->copy()->startOfMonth();

        if ($month === null) {
            $this->skip('entrada', $pageId, 'sem_mes', $row['Pagamento'] ?? null);
            $this->stats['skipped_no_month']++;

            return;
        }

        $issueDate = $dataDate ?? $month->copy();
        $dueDate = $dataDate?->copy() ?? $month->copy()->endOfMonth();
        $externalRef = 'notion:entrada:'.$pageId;
        $paid = (bool) ($row['Pago'] ?? false);
        $method = $this->invoiceMethod($row['Forma de Pagamento'] ?? null);

        $invoice = BillingInvoice::where('external_ref', $externalRef)->first();

        if ($invoice !== null) {
            $this->stats['invoices_existing']++;
        } else {
            $status = $paid
                ? BillingInvoice::STATUS_SENT
                : ($dueDate->copy()->startOfDay()->lt(now()->startOfDay())
                    ? BillingInvoice::STATUS_OVERDUE
                    : BillingInvoice::STATUS_SENT);

            $invoice = BillingInvoice::create([
                'number' => $numbers->next($issueDate),
                'contract_id' => null,
                'prospect_id' => $prospect->id,
                'period_start' => $month->copy()->startOfMonth()->toDateString(),
                'period_end' => $month->copy()->endOfMonth()->toDateString(),
                'issue_date' => $issueDate->toDateString(),
                'due_date' => $dueDate->toDateString(),
                'amount_cents' => $amountCents,
                'paid_cents' => 0,
                'status' => $status,
                'payment_method' => $method,
                'external_ref' => $externalRef,
                'notes' => $this->entradaNotes($row),
            ]);

            $invoice->items()->create([
                'description' => $this->entradaItemDescription($row),
                'quantity' => 1,
                'unit_amount_cents' => $amountCents,
                'total_cents' => $amountCents,
                'sort_order' => 0,
            ]);

            $this->stats['invoices_created']++;
        }

        if (! $paid) {
            return;
        }

        $paymentRef = $externalRef.':payment';

        if (BillingPayment::where('external_ref', $paymentRef)->exists()) {
            $this->stats['payments_existing']++;

            return;
        }

        $payments->record([
            'invoice_id' => $invoice->id,
            'prospect_id' => $prospect->id,
            'amount_cents' => $amountCents,
            'method' => $method,
            'paid_at' => $issueDate->toDateString(),
            'reference' => $row['Pagamento'] ?? null,
            'external_ref' => $paymentRef,
            'source' => BillingPayment::SOURCE_NOTION,
            'notes' => 'Importado do Notion Financeiro.',
        ]);

        $this->stats['payments_created']++;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string|null>  $people
     */
    private function importSaida(array $row, array $people): void
    {
        $pageId = (string) ($row['_page_id'] ?? '');
        $amountCents = $this->toCents($row['Valor'] ?? null);

        if ($amountCents === null || $amountCents <= 0) {
            $this->skip('saida', $pageId, 'sem_valor', $row['Name'] ?? null);
            $this->stats['skipped_no_amount']++;

            return;
        }

        if (BillingExpense::where('notion_page_id', $pageId)->exists()) {
            $this->stats['expenses_existing']++;

            return;
        }

        $month = $this->monthFor($row);
        $dataDate = ! empty($row['Data']) ? Carbon::parse((string) $row['Data']) : null;
        $month ??= $dataDate?->copy()->startOfMonth();

        if ($month === null) {
            $this->skip('saida', $pageId, 'sem_mes', $row['Name'] ?? null);
            $this->stats['skipped_no_month']++;

            return;
        }

        $paidAt = $dataDate ?? $month->copy()->endOfMonth();
        $description = trim((string) ($row['Name'] ?? '')) ?: 'Despesa importada do Notion';

        $payerNames = [];
        foreach (($row['Pago por'] ?? []) as $personId) {
            $name = $people[(string) $personId] ?? null;

            if (filled($name)) {
                $payerNames[] = $name;
            }
        }

        $designerNames = [];
        foreach (($row['Designer'] ?? []) as $personId) {
            $name = $people[(string) $personId] ?? null;

            if (filled($name)) {
                $designerNames[] = $name;
            }
        }

        $createdBy = null;
        foreach ($payerNames as $payerName) {
            $createdBy = $this->usersByName[$this->nameKey($payerName)] ?? null;

            if ($createdBy !== null) {
                break;
            }
        }

        $notes = ['Importado do Notion Financeiro.'];

        if ($payerNames !== []) {
            $notes[] = 'Pago por: '.implode(', ', $payerNames).'.';
        }

        if ($designerNames !== []) {
            $notes[] = 'Designer: '.implode(', ', $designerNames).'.';
        }

        BillingExpense::create([
            'description' => $description,
            'vendor' => null,
            'category' => $this->expenseCategory($description),
            'amount_cents' => $amountCents,
            'competence_month' => $month->copy()->startOfMonth()->toDateString(),
            'due_date' => $paidAt->toDateString(),
            'paid_at' => $paidAt->toDateString(),
            'status' => BillingExpense::STATUS_PAID,
            'payment_method' => BillingExpense::METHOD_OTHER,
            'recurrence' => BillingExpense::RECURRENCE_ONE_OFF,
            'notes' => implode(' ', $notes),
            'created_by' => $createdBy,
            'notion_page_id' => $pageId,
        ]);

        $this->stats['expenses_created']++;
    }

    /**
     * @param  array<string, mixed>  $clients
     * @param  array<string, mixed>  $row
     */
    private function resolveClient(string $clientId, array $clients, array $row): ?Prospect
    {
        $clientKey = $clientId;

        if ($clientId !== '' && isset($this->prospectsByLegacy[$clientId])) {
            $this->markClientResolved($clientKey, 'clients_matched');

            return $this->prospectsByLegacy[$clientId];
        }

        $client = $clientId !== '' ? ($clients[$clientId] ?? []) : [];
        $name = trim((string) ($client['Name'] ?? ($row['Nome'][0] ?? '')));
        $email = mb_strtolower(trim((string) ($client['Email'] ?? ($row['Email'][0] ?? ''))));
        $phone = Prospect::normalizePhone($client['Telefone'] ?? null);
        $instagram = $this->instagramKey($client['Instagram'] ?? null);

        if ($clientKey === '') {
            $clientKey = 'name:'.$this->nameKey($name).'|email:'.$email.'|phone:'.$this->phoneKey($phone);
        }

        $prospect = null;

        if ($phone !== null) {
            $prospect = $this->prospectsByPhone[$this->phoneKey($phone)] ?? null;
        }

        if ($prospect === null && $email !== '') {
            $prospect = $this->prospectsByEmail[$email] ?? null;
        }

        if ($prospect === null && $name !== '') {
            $prospect = $this->prospectsByName[$this->nameKey($name)] ?? null;
        }

        if ($prospect === null && $instagram !== null) {
            $prospect = $this->prospectsByInstagram[$instagram] ?? null;
        }

        if ($prospect !== null) {
            $this->markClientResolved($clientKey, 'clients_matched');
            $this->fillClientGaps($prospect, $email, $instagram);

            if ($clientId !== '') {
                $this->prospectsByLegacy[$clientId] = $prospect;
            }

            return $prospect;
        }

        $prospect = $this->createHistoricalClient($clientId, $name, $email, $instagram, $client);
        $this->markClientResolved($clientKey, 'clients_created');

        return $prospect;
    }

    private function markClientResolved(string $clientKey, string $stat): void
    {
        if (isset($this->resolvedClientIds[$clientKey])) {
            return;
        }

        $this->resolvedClientIds[$clientKey] = true;
        $this->stats[$stat]++;
    }

    private function fillClientGaps(Prospect $prospect, string $email, ?string $instagram): void
    {
        $updates = [];

        if (blank($prospect->email) && $email !== '') {
            $updates['email'] = $email;
        }

        if (blank($prospect->instagram) && $instagram !== null) {
            $updates['instagram'] = $instagram;
        }

        if ($updates !== []) {
            $prospect->update($updates);
            $this->stats['clients_updated']++;
        }
    }

    /**
     * @param  array<string, mixed>  $client
     */
    private function createHistoricalClient(
        string $clientId,
        string $name,
        string $email,
        ?string $instagram,
        array $client,
    ): Prospect {
        do {
            $phone = '+550000000'.str_pad((string) $this->placeholderSequence++, 4, '0', STR_PAD_LEFT);
        } while (isset($this->prospectsByPhone[$this->phoneKey($phone)]));

        $prospect = Prospect::create([
            'name' => $name !== '' ? $name : 'Cliente histórico Notion',
            'whatsapp_e164' => $phone,
            'email' => $email !== '' ? $email : null,
            'instagram' => $instagram,
            'status' => Prospect::STATUS_ARCHIVED,
            'source' => 'notion_finance',
            'legacy_notion_id' => $clientId !== '' ? $clientId : null,
            'legacy_status' => 'finance_import',
            'import_flags' => ['sem_telefone_notion', 'importado_notion_financeiro'],
            'notes' => 'Importado do Notion Financeiro sem telefone/e-mail. Atualizar contato.',
            'raw_payload' => ['notion_client' => $client],
        ]);

        $this->prospectsByPhone[$this->phoneKey($prospect->whatsapp_e164)] = $prospect;
        $this->prospectsByName[$this->nameKey($prospect->name)] = $prospect;

        if ($email !== '') {
            $this->prospectsByEmail[$email] = $prospect;
        }

        if ($instagram !== null) {
            $this->prospectsByInstagram[$instagram] = $prospect;
        }

        if ($clientId !== '') {
            $this->prospectsByLegacy[$clientId] = $prospect;
        }

        return $prospect;
    }

    private function loadProspects(): void
    {
        foreach (Prospect::query()->get() as $prospect) {
            if (filled($prospect->legacy_notion_id)) {
                $this->prospectsByLegacy[(string) $prospect->legacy_notion_id] = $prospect;
            }

            if (filled($prospect->whatsapp_e164)) {
                $this->prospectsByPhone[$this->phoneKey($prospect->whatsapp_e164)] = $prospect;
            }

            if (filled($prospect->email)) {
                $this->prospectsByEmail[mb_strtolower(trim($prospect->email))] = $prospect;
            }

            if (filled($prospect->name)) {
                $this->prospectsByName[$this->nameKey($prospect->name)] = $prospect;
            }

            if (filled($prospect->instagram)) {
                $key = $this->instagramKey($prospect->instagram);

                if ($key !== null) {
                    $this->prospectsByInstagram[$key] = $prospect;
                }
            }
        }
    }

    private function loadUsers(): void
    {
        foreach (User::query()
            ->whereIn('role', [User::ROLE_STAFF, User::ROLE_SALES])
            ->get() as $user) {
            $this->usersByName[$this->nameKey($user->name)] = $user->id;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $overview
     */
    private function loadMonths(array $overview): void
    {
        foreach ($overview as $row) {
            $pageId = (string) ($row['_page_id'] ?? '');
            $month = $this->monthNumber($row['Mes'] ?? null);
            $year = (int) ($row['Ano Fiscal'] ?? 0);

            if ($pageId !== '' && $month !== null && $year > 0) {
                $this->monthsByPageId[$pageId] = Carbon::create($year, $month, 1)->startOfMonth();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function monthFor(array $row): ?Carbon
    {
        foreach (($row['Mes'] ?? []) as $pageId) {
            if (isset($this->monthsByPageId[(string) $pageId])) {
                return $this->monthsByPageId[(string) $pageId]->copy();
            }
        }

        return null;
    }

    private function monthNumber(mixed $value): ?int
    {
        $key = mb_strtolower(Str::ascii(trim((string) $value)));

        return self::MONTHS[$key] ?? null;
    }

    private function invoiceMethod(mixed $value): string
    {
        return match (mb_strtolower(Str::ascii(trim((string) $value)))) {
            'pix' => BillingInvoice::METHOD_PIX,
            'cartao', 'credit_card' => BillingInvoice::METHOD_CREDIT_CARD,
            default => BillingInvoice::METHOD_OTHER,
        };
    }

    private function expenseCategory(string $description): string
    {
        $key = mb_strtolower(Str::ascii($description));

        return match (true) {
            str_contains($key, 'facebook'),
            str_contains($key, 'criativo'),
            str_contains($key, 'caption'),
            str_contains($key, 'anuncio') => BillingExpense::CATEGORY_ADS,
            str_contains($key, 'canva'),
            str_contains($key, 'notion'),
            str_contains($key, 'capcut'),
            str_contains($key, 'hostgator'),
            str_contains($key, 'dominio'),
            str_contains($key, 'telefone'),
            str_contains($key, 'headset') => BillingExpense::CATEGORY_SOFTWARE,
            str_contains($key, 'contraktor'),
            str_contains($key, 'gestor'),
            str_contains($key, 'atendimento'),
            str_contains($key, 'comissao') => BillingExpense::CATEGORY_CONTRACTORS,
            str_contains($key, 'contador'),
            str_contains($key, 'imposto') => BillingExpense::CATEGORY_TAXES,
            str_contains($key, 'cartao'),
            str_contains($key, 'aluguel'),
            str_contains($key, 'estrutura') => BillingExpense::CATEGORY_OFFICE,
            default => BillingExpense::CATEGORY_OTHER,
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function entradaNotes(array $row): string
    {
        $notes = ['Importado do Notion Financeiro.'];

        if (filled($row['Nr'] ?? null)) {
            $notes[] = 'Entrada Nr '.$row['Nr'].'.';
        }

        if (filled($row['Pagamento'] ?? null)) {
            $notes[] = 'Pagamento: '.$row['Pagamento'].'.';
        }

        if (filled($row['Status'] ?? null)) {
            $notes[] = 'Status Notion: '.$row['Status'].'.';
        }

        return implode(' ', $notes);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function entradaItemDescription(array $row): string
    {
        $service = null;
        $json = (string) ($row['services_json'] ?? '');

        if ($json !== '' && ! str_contains($json, '|')) {
            $decoded = json_decode($json, true);

            if (is_array($decoded) && filled($decoded['service'] ?? null)) {
                $service = (string) $decoded['service'];
            }
        }

        if ($service === null && ! empty($row['Produto']) && is_array($row['Produto'])) {
            $service = implode(' + ', $row['Produto']);
        }

        $description = $service !== null ? 'Serviço — '.$service : 'Entrada importada do Notion';

        if (filled($row['Periodo Referente'] ?? null)) {
            $description .= ' — '.$row['Periodo Referente'];
        }

        return $description;
    }

    private function toCents(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) round(((float) $value) * 100);
    }

    private function phoneKey(mixed $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }

    private function nameKey(mixed $value): string
    {
        $value = mb_strtolower(Str::ascii(trim((string) $value)));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    private function instagramKey(mixed $value): ?string
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

        return $value !== '' ? mb_strtolower($value) : null;
    }

    private function skip(string $source, string $pageId, string $reason, mixed $label): void
    {
        $this->skipped[] = [
            'source' => $source,
            'notion_page_id' => $pageId,
            'reason' => $reason,
            'label' => $label,
        ];
    }

    private function report(bool $dryRun): void
    {
        $this->line($dryRun
            ? '<comment>Dry-run: nada foi gravado.</comment>'
            : '<info>Importação concluída.</info>');

        $rows = [];

        foreach ($this->stats as $key => $value) {
            $rows[] = [Str::headline(str_replace('_', ' ', $key)), $value];
        }

        $this->table(['Métrica', 'Total'], $rows);

        if ($this->skipped !== []) {
            $this->warn(count($this->skipped).' linha(s) ignorada(s); use --report para ver os detalhes.');
        }
    }

    private function writeReport(string $path, bool $dryRun): void
    {
        $payload = [
            'generated_at' => now()->toIso8601String(),
            'dry_run' => $dryRun,
            'stats' => $this->stats,
            'skipped' => $this->skipped,
        ];

        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
