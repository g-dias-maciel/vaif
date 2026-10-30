<?php

namespace App\Http\Controllers;

use App\Models\ArtistSetting;
use App\Models\CalendarEntry;
use App\Models\PriceBook;
use App\Services\Google\GoogleCalendarService;
use App\Services\Google\GoogleCalendarSync;
use App\Services\WahaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class SettingsController extends Controller
{
    /**
     * The Artist's agent configuration: SDR on/off, WhatsApp, price table.
     */
    public function index(Request $request)
    {
        $artist = $this->activeArtist();

        if (! $artist) {
            return redirect()->route('artists.index');
        }

        $artist->loadMissing('settings');

        $pricing = PriceBook::query()
            ->where('artist_id', $artist->id)
            ->whereNull('effective_to')
            ->get();

        $pricingByKey = [];
        foreach ($pricing as $p) {
            $pricingByKey[$p->placement][$p->body_zone] = $p;
        }

        $sizes = config('crm.pricing_sizes');
        $regions = config('crm.pricing_regions');

        // Merge custom sizes + locations stored on the artist settings.
        $extras = $artist->settings?->pricing_extras ?? [];
        foreach ($extras['sizes'] ?? [] as $size) {
            $sizes[$size] = ucfirst(str_replace('_', ' ', $size));
        }

        // Placements that exist in the price book but not in the config list
        // (custom locations) are grouped under "Outros".
        $known = [];
        foreach ($regions as $region) {
            $known = array_merge($known, array_keys($region['locations']));
        }
        $known = array_merge($known, array_keys($sizes));
        $custom = [];
        foreach (array_keys($pricingByKey) as $placement) {
            if (! in_array($placement, $known, true)) {
                $custom[$placement] = ucfirst(str_replace('_', ' ', $placement));
            }
        }
        if ($custom !== []) {
            $regions['outros'] = ['label' => 'Outros', 'locations' => $custom];
        }

        foreach ($extras['locations'] ?? [] as $loc) {
            $region = $loc['region'] ?? null;
            $name = $loc['name'] ?? null;
            if ($region && $name) {
                $regions[$region] ??= ['label' => ucfirst($region), 'locations' => []];
                $regions[$region]['locations'][$name] = ucfirst(str_replace('_', ' ', $name));
            }
        }

        $waha = null;
        $qr = null;
        if ($artist->wa_session_slug) {
            $service = WahaService::make();
            $waha = $service->status($artist);
            if ($waha && ! $waha['connected']) {
                $qr = $service->qr($artist);
            }
        }

        $google = app(GoogleCalendarService::class);
        $googleConfigured = $google->isConfigured();

        return view('settings.index', [
            'artist' => $artist,
            'googleConfigured' => $googleConfigured,
            'googleServiceEmail' => $googleConfigured ? $google->serviceAccountEmail() : null,
            'promotions' => $artist->promotions()->orderByDesc('created_at')->get(),
            'pricingByKey' => $pricingByKey,
            'sizes' => $sizes,
            'regions' => $regions,
            'waha' => $waha,
            'qr' => $qr,
            'stateLabels' => [
                'WORKING' => 'Conectado',
                'SCAN_QR_CODE' => 'Aguardando leitura do QR code',
                'STARTING' => 'Iniciando…',
                'FAILED' => 'Falhou',
                'STOPPED' => 'Desconectado',
            ],
        ]);
    }

    /**
     * Create (or recreate) the WAHA session for the Artist.
     */
    public function connectWaha(Request $request)
    {
        $artist = $this->activeArtist();

        abort_unless($artist && $artist->wa_session_slug, 403);

        $ok = WahaService::make()->create($artist);

        return back()->with($ok ? 'status' : 'error', $ok
            ? 'Sessão WhatsApp iniciada. Escaneie o QR code para conectar.'
            : 'Não foi possível conectar ao WhatsApp agora. Tente novamente.');
    }

    /**
     * Save the PIX key the agent uses when requesting the deposit.
     */
    public function updatePayment(Request $request)
    {
        $artist = $this->activeArtist();

        abort_unless($artist, 403);

        $data = $request->validate([
            'pix_key' => ['nullable', 'string', 'max:255'],
            'deposit_percentage' => ['nullable', 'numeric', 'min:1', 'max:100'],
        ]);

        $pixKey = trim((string) ($data['pix_key'] ?? ''));

        $update = [
            'pix_key' => $pixKey !== '' ? $pixKey : null,
        ];

        // The signal percentage is what the agent quotes and what feeds
        // request_deposit. Saving the form always switches it to percentage.
        if (($data['deposit_percentage'] ?? null) !== null) {
            $update['deposit_type'] = 'percent';
            $update['deposit_value'] = (float) $data['deposit_percentage'];
        }

        $this->settings($artist)->update($update);

        $message = 'Configurações de sinal e PIX salvas.';
        if (($data['deposit_percentage'] ?? null) === null && $pixKey === '') {
            $message = 'Chave PIX removida.';
        }

        return back()->with('status', $message);
    }

    /**
     * Save the maximum discount the SDR may negotiate. The database stores
     * the inverse (`floor_pct`) because the quote floor is what the RPCs use.
     */
    public function updateNegotiation(Request $request)
    {
        $artist = $this->activeArtist();

        abort_unless($artist, 403);

        $data = $request->validate([
            'max_discount' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $this->settings($artist)->update([
            'floor_pct' => 100 - (float) $data['max_discount'],
        ]);

        return back()->with('status', 'Desconto máximo salvo.');
    }

    /**
     * Connect the Artist's shared Google Calendar after checking that the
     * service account can actually read/write it.
     */
    public function connectGoogle(Request $request, GoogleCalendarService $google)
    {
        $artist = $this->activeArtist();

        abort_unless($artist, 403);

        $data = $request->validate([
            'calendar_id' => ['required', 'string', 'max:255'],
        ]);

        $calendarId = trim($data['calendar_id']);

        try {
            $calendar = $google->getCalendar($calendarId);
        } catch (Throwable $e) {
            return back()->with('error', 'Não foi possível acessar essa agenda: '.$e->getMessage());
        }

        if (in_array($calendar['access_role'] ?? null, ['reader', 'freeBusyReader'], true)) {
            return back()->with('error', 'Essa agenda foi compartilhada apenas para leitura. Compartilhe com a permissão “Fazer alterações nos eventos”.');
        }

        $artist->forceFill([
            'google_calendar_id' => $calendar['id'],
            'google_calendar_summary' => $calendar['summary'],
            'google_calendar_timezone' => $calendar['timezone'],
            'google_connected_at' => now(),
        ])->save();

        // Queue existing upcoming bookings for the next scheduler pass. Also
        // queue cancelled bookings so an event created before a disconnect is
        // deleted after reconnecting.
        CalendarEntry::where('artist_id', $artist->id)
            ->where('kind', 'booking')
            ->where(function ($query) {
                $query->where(function ($upcoming) {
                    $upcoming->where('active', true)
                        ->where('start_at', '>=', now()->subDay());
                })->orWhere('active', false);
            })
            ->update([
                'google_sync_pending' => true,
                'google_sync_error' => null,
            ]);

        return back()->with('status', 'Google Agenda conectada. Os agendamentos serão sincronizados em até 1 minuto.');
    }

    /**
     * List the calendars shared with the VAIF service account so the Artist
     * can pick one instead of hunting for the Calendar ID.
     */
    public function detectGoogleCalendars(GoogleCalendarService $google)
    {
        $artist = $this->activeArtist();

        abort_unless($artist, 403);

        try {
            $calendars = $google->listCalendars();
        } catch (Throwable $e) {
            return back()->with('error', 'Não foi possível listar as agendas: '.$e->getMessage());
        }

        // One service account sees every Artist's shared calendar. Artists may
        // only see calendars matching their own account e-mail; staff may see
        // the full list when configuring an Artist.
        if (! auth()->user()->isStaff()) {
            $emails = $artist->users()
                ->pluck('email')
                ->push(auth()->user()->email)
                ->filter()
                ->map(fn (string $email) => mb_strtolower(trim($email)))
                ->unique()
                ->all();

            $calendars = array_values(array_filter(
                $calendars,
                fn (array $calendar) => in_array(mb_strtolower($calendar['id']), $emails, true),
            ));

            if ($calendars === []) {
                return back()->with('error', 'Não encontramos uma agenda com o e-mail da sua conta. Compartilhe a agenda com o e-mail da VAIF e informe o ID da agenda abaixo.');
            }
        }

        if ($calendars === []) {
            return back()->with('error', 'Nenhuma agenda compartilhada encontrada. Compartilhe sua agenda com o e-mail da VAIF e tente novamente.');
        }

        return back()->with('google_calendars', $calendars);
    }

    /**
     * Push pending bookings now instead of waiting for the scheduler.
     */
    public function syncGoogle(GoogleCalendarService $google, GoogleCalendarSync $sync)
    {
        $artist = $this->activeArtist();

        abort_unless($artist && $artist->hasGoogleCalendar(), 403);

        // Refresh the calendar name/timezone too, so a change made in Google
        // (for example the display timezone) is reflected here.
        try {
            $calendar = $google->getCalendar($artist->google_calendar_id);

            $artist->forceFill([
                'google_calendar_summary' => $calendar['summary'],
                'google_calendar_timezone' => $calendar['timezone'],
            ])->save();
        } catch (Throwable $e) {
            return back()->with('error', 'Não foi possível atualizar os dados da agenda: '.$e->getMessage());
        }

        try {
            $counts = $sync->syncArtist($artist, 50);
        } catch (Throwable $e) {
            return back()->with('error', 'Não foi possível sincronizar agora: '.$e->getMessage());
        }

        $summary = sprintf(
            '%d criado(s), %d atualizado(s), %d removido(s)',
            $counts['created'],
            $counts['updated'],
            $counts['deleted'],
        );

        return back()->with(
            $counts['failed'] > 0 ? 'error' : 'status',
            $counts['failed'] > 0
                ? $summary.'; '.$counts['failed'].' falhou(aram). Tente novamente.'
                : $summary.'.',
        );
    }

    /**
     * Stop mirroring bookings. Events already in Google are left untouched.
     */
    public function disconnectGoogle()
    {
        $artist = $this->activeArtist();

        abort_unless($artist, 403);

        // Keep `google_event_id` so reconnecting to the same calendar updates
        // the existing events instead of creating duplicates. Reconnecting to a
        // different calendar turns the stale ids into 404s and they are
        // recreated there.
        CalendarEntry::where('artist_id', $artist->id)
            ->update([
                'google_sync_pending' => false,
                'google_sync_error' => null,
            ]);

        $artist->forceFill([
            'google_calendar_id' => null,
            'google_calendar_summary' => null,
            'google_calendar_timezone' => null,
            'google_connected_at' => null,
        ])->save();

        return back()->with('status', 'Google Agenda desconectada. Os eventos já criados permanecem na agenda.');
    }

    /**
     * Restart the WAHA session (fix a stuck / bugged session).
     */
    public function restartWaha(Request $request)
    {
        $artist = $this->activeArtist();

        abort_unless($artist && $artist->wa_session_slug, 403);

        $ok = WahaService::make()->restart($artist);

        return back()->with($ok ? 'status' : 'error', $ok
            ? 'Sessão WhatsApp reiniciada.'
            : 'Não foi possível reiniciar a sessão agora.');
    }

    /**
     * Pause / reactivate the SDR agent for the Artist.
     */
    public function toggle(Request $request)
    {
        $artist = $this->activeArtist();

        abort_unless($artist, 403);

        return back()->with('status', $artist->toggleAgent());
    }

    /**
     * Bulk save a region's price matrix.
     *
     * Prices are versioned: changing a cell closes the current row and inserts
     * a new one, so quotes already sent keep their original price.
     */
    public function storePricingBulk(Request $request)
    {
        $artist = $this->activeArtist();

        abort_unless($artist, 403);

        $artistId = $artist->id;
        $cells = $request->input('cells', []);

        $saved = 0;
        $removed = 0;

        DB::transaction(function () use ($artistId, $cells, &$saved, &$removed) {
            $now = now();

            foreach ($cells as $placement => $zones) {
                if (! is_array($zones)) {
                    continue;
                }

                foreach ($zones as $bodyZone => $values) {
                    $price = trim((string) ($values['price'] ?? ''));
                    $duration = max(1, (int) ($values['duration'] ?? 120));

                    $current = PriceBook::query()
                        ->where('artist_id', $artistId)
                        ->where('placement', $placement)
                        ->where('body_zone', $bodyZone)
                        ->whereNull('effective_to')
                        ->first();

                    if ($price === '') {
                        if ($current) {
                            $current->update(['effective_to' => $now]);
                            $removed++;
                        }

                        continue;
                    }

                    $priceCents = max(0, (int) round((float) str_replace(',', '.', $price) * 100));

                    if ($current
                        && $current->base_price_cents === $priceCents
                        && $current->session_duration_min === $duration) {
                        continue;
                    }

                    if ($current) {
                        $current->update(['effective_to' => $now]);
                    }

                    PriceBook::create([
                        'artist_id' => $artistId,
                        'placement' => $placement,
                        'body_zone' => $bodyZone,
                        'base_price_cents' => $priceCents,
                        'session_duration_min' => $duration,
                        'buffer_min' => 30,
                        'effective_from' => $now,
                    ]);

                    $saved++;
                }
            }
        });

        $message = "$saved preço(s) salvo(s).";
        if ($removed > 0) {
            $message .= " $removed removido(s).";
        }

        return back()->with('status', $message);
    }

    /**
     * Add a custom sub-location under a region.
     */
    public function addLocation(Request $request)
    {
        $artist = $this->activeArtist();

        abort_unless($artist, 403);

        $data = $request->validate([
            'region' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $settings = $this->settings($artist);
        $extras = $settings->pricing_extras ?? [];
        $locations = $extras['locations'] ?? [];
        $locations[] = ['region' => $data['region'], 'name' => $data['name']];
        $extras['locations'] = $locations;
        $settings->update(['pricing_extras' => $extras]);

        return back()->with('status', 'Local adicionado.');
    }

    /**
     * Add a custom size.
     */
    public function addSize(Request $request)
    {
        $artist = $this->activeArtist();

        abort_unless($artist, 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $settings = $this->settings($artist);
        $extras = $settings->pricing_extras ?? [];
        $sizes = $extras['sizes'] ?? [];
        if (! in_array($data['name'], $sizes, true)) {
            $sizes[] = $data['name'];
        }
        $extras['sizes'] = $sizes;
        $settings->update(['pricing_extras' => $extras]);

        return back()->with('status', 'Tamanho adicionado.');
    }

    private function settings(\App\Models\Artist $artist): ArtistSetting
    {
        return $artist->settings()->firstOrCreate(['artist_id' => $artist->id]);
    }
}
