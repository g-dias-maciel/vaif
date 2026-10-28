<?php

namespace App\Http\Controllers;

use App\Models\CalendarEntry;
use App\Services\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BookingController extends Controller
{
    /**
     * The Artist's bookings, with an upcoming / past / all filter, plus
     * blocked periods.
     */
    public function index(Request $request)
    {
        $artist = $this->activeArtist();

        if (! $artist) {
            return redirect()->route('artists.index');
        }

        $filter = in_array($request->query('filter'), ['upcoming', 'past', 'all'], true)
            ? $request->query('filter')
            : 'upcoming';

        $bookingsQuery = CalendarEntry::with(['lead', 'project'])
            ->where('artist_id', $artist->id)
            ->where('kind', 'booking')
            ->where('active', true);

        if ($filter === 'upcoming') {
            $bookingsQuery->where('start_at', '>=', now())->orderBy('start_at');
        } elseif ($filter === 'past') {
            $bookingsQuery->where('start_at', '<', now())->orderByDesc('start_at');
        } else {
            $bookingsQuery->orderBy('start_at');
        }

        $bookings = $bookingsQuery->get();

        $blocked = CalendarEntry::where('artist_id', $artist->id)
            ->where('kind', 'block')
            ->where('active', true)
            ->where('end_at', '>=', now())
            ->orderBy('start_at')
            ->get();

        return view('bookings.index', [
            'artist' => $artist,
            'bookings' => $bookings,
            'blocked' => $blocked,
            'filter' => $filter,
        ]);
    }

    /**
     * Block a period so the agent won't book it (day off / personal slot).
     */
    public function block(Request $request)
    {
        $artist = $this->activeArtist();

        if (! $artist) {
            return redirect()->route('artists.index');
        }

        $data = $request->validate([
            'date' => ['required', 'string', 'max:10'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
        ]);

        // The form shows dd/mm/yyyy (pt-BR); flatpickr submits Y-m-d. Accept
        // both so the page still works if the date picker script is blocked.
        $rawDate = trim((string) $data['date']);
        $date = null;
        foreach (['!d/m/Y', '!Y-m-d'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $rawDate);
            if ($parsed && $parsed->format($format === '!d/m/Y' ? 'd/m/Y' : 'Y-m-d') === $rawDate) {
                $date = $parsed;
                break;
            }
        }

        if (! $date) {
            return back()->withInput()->withErrors(['date' => 'Data inválida. Use dd/mm/aaaa.']);
        }

        $dateString = $date->format('Y-m-d');
        $tz = $artist->timezone ?: 'America/Sao_Paulo';

        if (! empty($data['start_time']) && ! empty($data['end_time'])) {
            $start = CarbonImmutable::parse($dateString.' '.$data['start_time'], $tz);
            $end = CarbonImmutable::parse($dateString.' '.$data['end_time'], $tz);
        } else {
            $start = CarbonImmutable::parse($dateString.' 00:00', $tz);
            $end = CarbonImmutable::parse($dateString.' 23:59:59', $tz);
        }

        if ($end->lessThanOrEqualTo($start)) {
            return back()->withErrors(['date' => 'O horário final deve ser depois do inicial.']);
        }

        $created = DB::select(
            'select id from block_time(?, ?, ?, ?, ?)',
            [$artist->id, $start, $end, 'Bloqueio manual', 'artist'],
        );

        if ($created === []) {
            return back()->withErrors(['date' => 'Este período já está ocupado por um agendamento ou bloqueio.']);
        }

        return back()->with('status', 'Período bloqueado.');
    }

    /**
     * Remove a blocked period.
     */
    public function unblock(CalendarEntry $slot)
    {
        $this->authorize('unblock', $slot);

        DB::select('select unblock_time(?, ?)', [$slot->artist_id, $slot->id]);

        return back()->with('status', 'Período desbloqueado.');
    }

    /**
     * Mark the booking's sinal (deposit) as received.
     */
    public function confirmDeposit(Request $request, CalendarEntry $slot)
    {
        $this->authorize('reschedule', $slot);

        if (! $slot->project_id) {
            return back()->withErrors(['deposit' => 'Este agendamento não tem um projeto associado.']);
        }

        try {
            DB::select('select * from confirm_deposit(?, ?, ?, ?, ?)', [
                $slot->artist_id,
                $slot->project_id,
                'manual',
                null,
                'artist',
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            return back()->withErrors(['deposit' => 'Não foi possível confirmar o sinal: nenhum sinal solicitado.']);
        }

        return back()->with('status', 'Sinal confirmado.');
    }

    /**
     * Cancel a booking: the entry stays for audit but stops blocking the slot.
     */
    public function cancel(Request $request, CalendarEntry $slot)
    {
        $this->authorize('reschedule', $slot);

        DB::select('select * from cancel_booking(?, ?, ?, ?)', [
            $slot->artist_id,
            $slot->id,
            null,
            'artist',
        ]);

        return back()->with('status', 'Agendamento cancelado.');
    }

    /**
     * Reschedule form: list the free slots the Artist can move this booking to.
     */
    public function rescheduleForm(Request $request, CalendarEntry $slot)
    {
        $this->authorize('reschedule', $slot);

        $duration = $slot->durationMinutes();

        $available = app(BookingService::class)->availableSlots(
            $slot->artist_id,
            now()->toImmutable(),
            now()->addDays(7)->toImmutable(),
            $duration,
        );

        return view('bookings.reschedule', [
            'slot' => $slot,
            'duration' => $duration,
            'available' => $available,
        ]);
    }

    /**
     * Perform the reschedule, validated by the calendar exclusion constraint.
     */
    public function reschedule(Request $request, CalendarEntry $slot)
    {
        $this->authorize('reschedule', $slot);

        $startAt = CarbonImmutable::parse((string) $request->input('start_at'));

        try {
            app(BookingService::class)->reschedule($slot, $startAt);
        } catch (RuntimeException $e) {
            return back()->withErrors(['start_at' => $e->getMessage()]);
        }

        return redirect()->route('bookings.index')->with('status', 'Agendamento remarcado.');
    }
}
