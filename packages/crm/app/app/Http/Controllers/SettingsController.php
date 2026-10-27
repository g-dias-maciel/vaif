<?php

namespace App\Http\Controllers;

use App\Models\Pricing;
use App\Services\WahaService;
use Illuminate\Http\Request;

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

        $pricing = Pricing::query()
            ->where('artist_id', $artist->id)
            ->get();

        $pricingByKey = [];
        foreach ($pricing as $p) {
            $pricingByKey[$p->placement][$p->body_zone] = $p;
        }

        $sizes = config('crm.pricing_sizes');
        $regions = config('crm.pricing_regions');

        // Merge custom sizes + locations stored on the artist.
        $extras = $artist?->pricing_extras ?? [];
        foreach ($extras['sizes'] ?? [] as $size) {
            $sizes[$size] = ucfirst(str_replace('_', ' ', $size));
        }
        foreach ($extras['locations'] ?? [] as $loc) {
            $region = $loc['region'] ?? null;
            $name = $loc['name'] ?? null;
            if ($region && $name && isset($regions[$region])) {
                $regions[$region]['locations'][$name] = ucfirst(str_replace('_', ' ', $name));
            }
        }

        $waha = null;
        $qr = null;
        if ($artist && $artist->wa_session_slug) {
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

        return back()->with('status', $ok
            ? 'Sessão WhatsApp criada. Escaneie o QR code para conectar.'
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

        return back()->with('status', $ok
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
     */
    public function storePricingBulk(Request $request)
    {
        $artist = $this->activeArtist();

        abort_unless($artist, 403);

        $artistId = $artist->id;
        $region = (string) $request->input('region');
        $cells = $request->input('cells', []);

        $saved = 0;
        $removed = 0;

        foreach ($cells as $placement => $zones) {
            if (! is_array($zones)) {
                continue;
            }

            foreach ($zones as $bodyZone => $values) {
                $price = trim((string) ($values['price'] ?? ''));
                $duration = (int) ($values['duration'] ?? 120);

                if ($price === '') {
                    $existing = Pricing::where('artist_id', $artistId)
                        ->where('placement', $placement)
                        ->where('body_zone', $bodyZone)
                        ->first();

                    if ($existing && $existing->active) {
                        $existing->update(['active' => false]);
                        $removed++;
                    }

                    continue;
                }

                $priceCents = max(0, (int) round((float) str_replace(',', '.', $price) * 100));

                Pricing::updateOrCreate(
                    ['artist_id' => $artistId, 'placement' => $placement, 'body_zone' => $bodyZone],
                    [
                        'region' => $region,
                        'table_price' => $priceCents,
                        'session_duration_min' => max(1, $duration),
                        'buffer_min' => 30,
                        'active' => true,
                    ],
                );

                $saved++;
            }
        }

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

        $extras = $artist->pricing_extras ?? [];
        $locations = $extras['locations'] ?? [];
        $locations[] = ['region' => $data['region'], 'name' => $data['name']];
        $extras['locations'] = $locations;
        $artist->update(['pricing_extras' => $extras]);

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

        $extras = $artist->pricing_extras ?? [];
        $sizes = $extras['sizes'] ?? [];
        if (! in_array($data['name'], $sizes, true)) {
            $sizes[] = $data['name'];
        }
        $extras['sizes'] = $sizes;
        $artist->update(['pricing_extras' => $extras]);

        return back()->with('status', 'Tamanho adicionado.');
    }
}
