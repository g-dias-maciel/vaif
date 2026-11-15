<?php

namespace App\Console\Commands;

use App\Models\Artist;
use App\Models\Event;
use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Read-only calibration report for the remarketing score.
 *
 * For every open stage, it answers: of the Projects that ever reached this
 * stage and have a final outcome, how many were won (agendado/fechado)? The
 * resulting rates are the `stage_base_rates` in config/crm.php.
 */
class ScoreBaseline extends Command
{
    protected $signature = 'crm:score-baseline
        {artist? : Artist slug or UUID (omit for every Artist)}
        {--min=10 : Minimum sample before a rate is considered reliable}';

    protected $description = 'Relata a taxa histórica de fechamento por etapa para calibrar o score de remarketing';

    private const WON_STAGES = ['agendado', 'fechado'];

    private const SKIP_STAGES = ['agendado', 'fechado', 'perdido', 'bloqueado'];

    public function handle(): int
    {
        $artists = $this->artists();

        if ($artists->isEmpty()) {
            $this->error('Nenhum artista encontrado.');

            return self::FAILURE;
        }

        $min = max(1, (int) $this->option('min'));

        foreach ($artists as $artist) {
            $this->newLine();
            $this->info($artist->displayName().' ('.$artist->wa_session_slug.')');

            $rows = $this->rowsFor($artist, $min);

            if ($rows === []) {
                $this->line('Sem projetos com desfecho suficiente para calibrar.');

                continue;
            }

            $this->table(
                ['Etapa', 'Chegaram', 'Fecharam', 'Taxa', 'Base atual', 'Amostra'],
                $rows,
            );
        }

        $this->newLine();
        $this->line('Leitura: "Chegaram" considera só projetos com desfecho (agendado, fechado, perdido).');
        $this->line('Amostra "baixa" significa menos de '.$min.' projetos; trate a taxa como indicativa.');

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Artist>
     */
    private function artists()
    {
        $arg = trim((string) $this->argument('artist'));
        $query = Artist::query()->orderBy('name');

        if ($arg !== '') {
            $query->where(function ($where) use ($arg) {
                $where->where('wa_session_slug', $arg);

                if (Str::isUuid($arg)) {
                    $where->orWhere('id', $arg);
                }
            });
        }

        return $query->get();
    }

    /**
     * @return array<int, array<int, string|int>>
     */
    private function rowsFor(Artist $artist, int $min): array
    {
        $projects = Project::query()
            ->where('artist_id', $artist->id)
            ->whereIn('stage_key', [...self::WON_STAGES, 'perdido'])
            ->get(['id', 'stage_key']);

        if ($projects->isEmpty()) {
            return [];
        }

        $projectIds = $projects->pluck('id')->all();

        $events = Event::query()
            ->where('artist_id', $artist->id)
            ->whereIn('project_id', $projectIds)
            ->where('type', 'stage_changed')
            ->get(['project_id', 'payload']);

        $visited = [];

        foreach ($projects as $project) {
            $visited[(string) $project->id][(string) $project->stage_key] = true;
        }

        foreach ($events as $event) {
            $stage = is_array($event->payload) ? ($event->payload['stage'] ?? null) : null;

            if (is_string($stage) && $stage !== '') {
                $visited[(string) $event->project_id][$stage] = true;
            }
        }

        $won = $projects
            ->filter(fn (Project $project): bool => in_array((string) $project->stage_key, self::WON_STAGES, true))
            ->pluck('id')
            ->flip()
            ->all();

        $baseRates = (array) config('crm.lead_score.stage_base_rates', []);
        $labels = (array) config('crm.pipeline_statuses', []);
        $rows = [];

        foreach ($labels as $stage => $label) {
            if (in_array($stage, self::SKIP_STAGES, true)) {
                continue;
            }

            $reached = 0;
            $closed = 0;

            foreach ($projects as $project) {
                if (! isset($visited[(string) $project->id][$stage])) {
                    continue;
                }

                $reached++;

                if (isset($won[(string) $project->id])) {
                    $closed++;
                }
            }

            if ($reached === 0) {
                continue;
            }

            $rate = round($closed / $reached * 100, 1);

            $rows[] = [
                $label,
                $reached,
                $closed,
                $rate.'%',
                (string) ($baseRates[$stage] ?? '—'),
                $reached >= $min ? 'ok' : 'baixa',
            ];
        }

        return $rows;
    }
}
