<?php

namespace App\Http\Controllers;

use App\Models\Prospect;
use App\Models\TeamEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Staff calendar: manual team meetings + prospect sales calls, in a month grid
 * with type/owner filters. Staff see everything; sales do not have access to
 * internal strategy meetings.
 */
class TeamCalendarController extends Controller
{
    private const MONTHS = [
        'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
        'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro',
    ];

    public function index(Request $request)
    {
        $month = $this->monthFromRequest($request);
        $monthStart = $month->copy()->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();

        $type = (string) $request->input('type', '');
        $type = array_key_exists($type, TeamEvent::typeOptions()) ? $type : null;

        $ownerId = (int) $request->input('owner_id');
        $ownerId = $ownerId > 0 ? $ownerId : null;

        $term = trim((string) $request->input('q'));
        $term = $term !== '' ? $term : null;

        $gridStart = $monthStart->copy()->startOfWeek(Carbon::SUNDAY)->startOfDay();
        $gridEnd = $monthEnd->copy()->endOfWeek(Carbon::SATURDAY)->endOfDay();

        $events = TeamEvent::query()
            ->with(['owner', 'prospect'])
            ->between($gridStart, $gridEnd)
            ->when($type !== null, fn ($query) => $query->where('type', $type))
            ->when($ownerId !== null, fn ($query) => $query->where('owner_id', $ownerId))
            ->when($term !== null, function ($query) use ($term): void {
                $like = '%'.$term.'%';
                $query->where(function ($inner) use ($like): void {
                    $inner->where('title', 'like', $like)
                        ->orWhere('location', 'like', $like)
                        ->orWhere('notes', 'like', $like);
                });
            })
            ->get()
            ->map(fn (TeamEvent $event): array => $this->eventPayload($event));

        $calls = collect();

        if ($type === null || $type === TeamEvent::TYPE_SALES) {
            $calls = Prospect::query()
                ->with('owner')
                ->whereNotNull('booked_call_at')
                ->whereIn('status', [Prospect::STATUS_ACTIVE, Prospect::STATUS_BACKLOG])
                ->whereBetween('booked_call_at', [$gridStart, $gridEnd])
                ->when($ownerId !== null, fn ($query) => $query->where('owner_id', $ownerId))
                ->when($term !== null, function ($query) use ($term): void {
                    $like = '%'.$term.'%';
                    $query->where(function ($inner) use ($like): void {
                        $inner->where('name', 'like', $like)
                            ->orWhere('instagram', 'like', $like)
                            ->orWhere('whatsapp_e164', 'like', $like);
                    });
                })
                ->get()
                ->map(fn (Prospect $prospect): array => $this->callPayload($prospect));
        }

        $all = $events->concat($calls)
            ->sortBy(fn (array $event): int => $event['start']->timestamp)
            ->values();

        $monthEvents = $all->filter(fn (array $event): bool => $event['start']->lt($monthEnd->copy()->endOfDay())
            && $event['end']->gte($monthStart));

        $byDay = $all->groupBy(fn (array $event): string => $event['start']->format('Y-m-d'));
        $weeks = $this->weeks($gridStart, $gridEnd, $byDay);

        $counts = [
            TeamEvent::TYPE_SALES => $monthEvents->where('type', TeamEvent::TYPE_SALES)->count(),
            TeamEvent::TYPE_STRATEGY => $monthEvents->where('type', TeamEvent::TYPE_STRATEGY)->count(),
            TeamEvent::TYPE_OTHER => $monthEvents->where('type', TeamEvent::TYPE_OTHER)->count(),
        ];

        return view('calendar.index', [
            'month' => $monthStart,
            'monthLabel' => self::MONTHS[$monthStart->month - 1].' '.$monthStart->year,
            'prevMonth' => $monthStart->copy()->subMonthNoOverflow()->format('Y-m'),
            'nextMonth' => $monthStart->copy()->addMonthNoOverflow()->format('Y-m'),
            'weeks' => $weeks,
            'monthEvents' => $monthEvents,
            'counts' => $counts,
            'types' => TeamEvent::typeOptions(),
            'owners' => $this->owners(),
        ]);
    }

    public function create()
    {
        $start = now()->addHour()->startOfHour();

        return view('calendar.form', [
            'event' => new TeamEvent([
                'type' => TeamEvent::TYPE_OTHER,
                'starts_at' => $start,
                'ends_at' => $start->copy()->addHour(),
                'owner_id' => auth()->id(),
            ]),
            'types' => TeamEvent::typeOptions(),
            'owners' => $this->owners(),
            'prospects' => $this->prospects(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $event = TeamEvent::create($this->validated($request));

        return redirect()
            ->route('calendar.index', ['month' => $event->starts_at->format('Y-m')])
            ->with('status', 'Compromisso criado.');
    }

    public function edit(TeamEvent $event)
    {
        return view('calendar.form', [
            'event' => $event,
            'types' => TeamEvent::typeOptions(),
            'owners' => $this->owners(),
            'prospects' => $this->prospects($event->prospect_id),
        ]);
    }

    public function update(Request $request, TeamEvent $event): RedirectResponse
    {
        $event->update($this->validated($request));

        return redirect()
            ->route('calendar.index', ['month' => $event->fresh()->starts_at->format('Y-m')])
            ->with('status', 'Compromisso atualizado.');
    }

    public function destroy(TeamEvent $event): RedirectResponse
    {
        $month = $event->starts_at->format('Y-m');
        $event->delete();

        return redirect()
            ->route('calendar.index', ['month' => $month])
            ->with('status', 'Compromisso removido.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(array_keys(TeamEvent::typeOptions()))],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'location' => ['nullable', 'string', 'max:255'],
            'meeting_url' => ['nullable', 'url', 'max:500'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'owner_id' => [
                'required',
                Rule::exists('users', 'id')->whereIn('role', [User::ROLE_STAFF, User::ROLE_SALES]),
            ],
            'prospect_id' => ['nullable', 'uuid', 'exists:prospects,id'],
        ]);

        $data['starts_at'] = Carbon::createFromFormat('Y-m-d\TH:i', $data['starts_at']);
        $data['ends_at'] = Carbon::createFromFormat('Y-m-d\TH:i', $data['ends_at']);

        if ($data['ends_at']->lte($data['starts_at'])) {
            throw ValidationException::withMessages([
                'ends_at' => 'O fim deve ser depois do início.',
            ]);
        }

        return $data;
    }

    /**
     * @param  \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, array<string, mixed>>>  $byDay
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function weeks(Carbon $gridStart, Carbon $gridEnd, $byDay): array
    {
        $weeks = [];
        $cursor = $gridStart->copy();

        while ($cursor->lte($gridEnd)) {
            $week = [];

            for ($i = 0; $i < 7; $i++) {
                $day = $cursor->copy();
                $week[] = [
                    'date' => $day,
                    'events' => $byDay->get($day->format('Y-m-d'), collect()),
                ];
                $cursor->addDay();
            }

            $weeks[] = $week;
        }

        return $weeks;
    }

    /**
     * @return array<string, mixed>
     */
    private function eventPayload(TeamEvent $event): array
    {
        return [
            'key' => 'event-'.$event->id,
            'kind' => 'event',
            'type' => $event->type,
            'type_label' => $event->typeLabel(),
            'type_tone' => $event->typeTone(),
            'title' => $event->title,
            'start' => $event->starts_at,
            'end' => $event->ends_at,
            'owner' => $event->owner?->name,
            'location' => $event->location,
            'meeting_url' => $event->meeting_url,
            'url' => route('calendar.events.edit', $event),
            'editable' => true,
            'prospect' => $event->prospect?->name,
            'prospect_url' => $event->prospect ? route('sales.prospects.show', $event->prospect) : null,
            'time_confirmed' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function callPayload(Prospect $prospect): array
    {
        $start = $prospect->booked_call_at;

        return [
            'key' => 'call-'.$prospect->id,
            'kind' => 'sales_call',
            'type' => TeamEvent::TYPE_SALES,
            'type_label' => 'Vendas',
            'type_tone' => 'sales',
            'title' => 'Call — '.$prospect->name,
            'start' => $start,
            'end' => $start->copy()->addHour(),
            'owner' => $prospect->owner?->name,
            'location' => null,
            'meeting_url' => null,
            'url' => route('sales.prospects.show', $prospect),
            'editable' => false,
            'prospect' => $prospect->name,
            'prospect_url' => route('sales.prospects.show', $prospect),
            'time_confirmed' => ! ($start->hour === 0 && $start->minute === 0),
        ];
    }

    private function owners()
    {
        return User::query()
            ->whereIn('role', array_keys(User::internalRoleOptions()))
            ->orderBy('name')
            ->get(['id', 'name', 'role']);
    }

    private function prospects(?string $includeId = null)
    {
        return Prospect::query()
            ->where(function ($query) use ($includeId): void {
                $query->where('status', Prospect::STATUS_ACTIVE);

                if ($includeId !== null) {
                    $query->orWhere('id', $includeId);
                }
            })
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function monthFromRequest(Request $request): Carbon
    {
        $value = (string) $request->input('month', now()->format('Y-m'));

        try {
            return Carbon::createFromFormat('Y-m', $value)->startOfMonth();
        } catch (\Throwable) {
            return now()->startOfMonth();
        }
    }
}
