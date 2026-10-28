<?php

namespace App\Http\Controllers;

use App\Models\ArtistSetting;
use App\Models\PriceBook;
use App\Services\WahaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        return view('settings.index', [
            'artist' => $artist,
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
