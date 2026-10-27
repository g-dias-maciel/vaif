<?php

namespace App\Http\Controllers;

use App\Models\CalendarSlot;
use App\Models\Event;
use App\Services\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
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

        $bookingsQuery = CalendarSlot::with(['lead', 'tattoo'])
            ->where('artist_id', $artist->id)
            ->where('type', 'booked');

        if ($filter === 'upcoming') {
            $bookingsQuery->where('start_at', '>=', now())->orderBy('start_at');
        } elseif ($filter === 'past') {
            $bookingsQuery->where('start_at', '<', now())->orderByDesc('start_at');
        } else {
            $bookingsQuery->orderBy('start_at');
        }

        $bookings = $bookingsQuery->get();

        $blocked = CalendarSlot::with(['lead', 'tattoo'])
            ->where('artist_id', $artist->id)
            ->where('type', 'blocked')
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
            'date' => ['required', 'date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
        ]);

        $tz = $artist->timezone ?: 'America/Sao_Paulo';

        if (! empty($data['start_time']) && ! empty($data['end_time'])) {
            $start = CarbonImmutable::parse($data['date'].' '.$data['start_time'], $tz);
            $end = CarbonImmutable::parse($data['date'].' '.$data['end_time'], $tz);
        } else {
            $start = CarbonImmutable::parse($data['date'].' 00:00', $tz);
            $end = CarbonImmutable::parse($data['date'].' 23:59:59', $tz);
        }

        if ($end->lessThanOrEqualTo($start)) {
            return back()->withErrors(['date' => 'O horário final deve ser depois do inicial.']);
        }

        CalendarSlot::create([
            'artist_id' => $artist->id,
            'type' => 'blocked',
            'start_at' => $start,
            'end_at' => $end,
        ]);

        return back()->with('status', 'Período bloqueado.');
    }

    /**
     * Remove a blocked period.
     */
    public function unblock(CalendarSlot $slot)
    {
        $this->authorize('unblock', $slot);

        $slot->delete();

        return back()->with('status', 'Período desbloqueado.');
    }

    /**
     * Mark the booking's sinal (deposit) as received.
     */
    public function confirmDeposit(Request $request, CalendarSlot $slot)
    {
        $this->authorize('reschedule', $slot);

        $tattoo = $slot->tattoo;

        if ($tattoo && $tattoo->deposit_status !== 'confirmado') {
            $tattoo->update(['deposit_status' => 'confirmado']);

            Event::create([
                'lead_id' => $slot->lead_id,
                'tattoo_id' => $tattoo->id,
                'artist_id' => $slot->artist_id,
                'event_type' => 'deposit_confirmed',
                'payload' => ['actor' => 'artist'],
            ]);
        }

        return back()->with('status', 'Sinal confirmado.');
    }

    /**
     * Cancel a booking: remove the slot and return the project to the pipeline.
     */
    public function cancel(Request $request, CalendarSlot $slot)
    {
        $this->authorize('reschedule', $slot);

        $tattoo = $slot->tattoo;

        $slot->delete();

        if ($tattoo) {
            $tattoo->update([
                'pipeline_status' => 'aguardando_deposito',
                'booked_date' => null,
            ]);

            Event::create([
                'lead_id' => $slot->lead_id,
                'tattoo_id' => $tattoo->id,
                'artist_id' => $slot->artist_id,
                'event_type' => 'booking_cancelled',
                'payload' => ['actor' => 'artist'],
            ]);
        }

        return back()->with('status', 'Agendamento cancelado.');
    }

    /**
     * Reschedule form: list the free slots the Artist can move this booking to.
     */
    public function rescheduleForm(Request $request, CalendarSlot $slot)
    {
        $this->authorize('reschedule', $slot);

        $duration = $this->slotDuration($slot);

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
     * Perform the reschedule, validated against the Artist's availability.
     */
    public function reschedule(Request $request, CalendarSlot $slot)
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

    private function slotDuration(CalendarSlot $slot): int
    {
        $minutes = (int) $slot->start_at->diffInMinutes($slot->end_at);

        return max(30, $minutes);
    }
}
