@php
    $lpBase = rtrim((string) config('content.lp.base_url', 'https://vaif.com.br'), '/');
    $absolute = function (string $url) use ($lpBase): string {
        if ($url === '' || preg_match('#^(https?:)?//#i', $url)) {
            return $url;
        }
        return $lpBase . '/' . ltrim($url, '/');
    };

    $styles = is_array($facts['styles'] ?? null) ? $facts['styles'] : [];
    $styleSummary = (string) ($facts['style_summary'] ?? implode(', ', $styles));
    $city = (string) ($facts['city'] ?? '');
    $state = (string) ($facts['state'] ?? '');
    $studio = (string) ($facts['studio_name'] ?? '');
    $address = (string) ($facts['address'] ?? '');
    $neighborhood = (string) ($facts['neighborhood'] ?? '');
    $zip = (string) ($facts['zip'] ?? '');
    $bio = (string) ($facts['bio_plain'] ?? '');
    $portfolio = is_array($facts['portfolio'] ?? null) ? $facts['portfolio'] : [];
    $beforeAfter = is_array($facts['before_after'] ?? null) ? $facts['before_after'] : [];
    $testimonials = is_array($facts['testimonials'] ?? null) ? $facts['testimonials'] : [];
    $faq = is_array($facts['faq'] ?? null) ? $facts['faq'] : [];
    $stats = is_array($facts['stats'] ?? null) ? $facts['stats'] : [];
    $hours = is_array($facts['opening_hours'] ?? null) ? $facts['opening_hours'] : null;
    $landingUrl = (string) ($facts['landing_page_url'] ?? '');
    $whatsappUrl = (string) ($facts['whatsapp_url'] ?? '');
    $instagramUrl = (string) ($facts['instagram_url'] ?? '');
    $source = (string) ($facts['source'] ?? '');
@endphp

<style>
    .artist-facts { border: 1px solid var(--border); border-radius: 14px; overflow: hidden; background: var(--panel); }
    .artist-facts-head { padding: 20px 22px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
    .artist-facts-head h2 { margin: 0 0 4px; font-family: 'Cormorant Garamond', serif; font-size: 1.8rem; }
    .artist-facts-head p { margin: 0; color: var(--muted); font-size: .82rem; }
    .artist-facts-links { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 8px; font-size: .78rem; }
    .artist-facts-badges { display: flex; flex-direction: column; gap: 6px; align-items: flex-end; }
    .artist-facts-badge { border: 1px solid var(--border); border-radius: 999px; padding: 4px 10px; font-size: .65rem; text-transform: uppercase; letter-spacing: .6px; color: var(--muted); }
    .artist-facts-body { padding: 20px 22px; }
    .artist-facts-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; margin-bottom: 20px; }
    .artist-facts-item { background: var(--panel-2); border: 1px solid var(--border); border-radius: 10px; padding: 10px 12px; }
    .artist-facts-item span { display: block; color: var(--muted); font-size: .62rem; text-transform: uppercase; letter-spacing: .6px; margin-bottom: 3px; }
    .artist-facts-item strong { font-size: .82rem; font-weight: 500; }
    .artist-facts-section { margin-top: 20px; }
    .artist-facts-section h3 { margin: 0 0 8px; font-size: .78rem; text-transform: uppercase; letter-spacing: .7px; color: var(--gold); }
    .artist-facts-section p { margin: 0 0 8px; line-height: 1.65; font-size: .88rem; }
    .artist-facts-media { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 8px; }
    .artist-facts-media img { width: 100%; aspect-ratio: 1 / 1; object-fit: cover; border-radius: 8px; border: 1px solid var(--border); }
    .artist-facts-pair { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
    .artist-facts-pair figure { margin: 0; }
    .artist-facts-pair figcaption { color: var(--muted); font-size: .68rem; margin-top: 4px; }
    .artist-facts-quote { border-left: 2px solid var(--gold); padding-left: 10px; margin-bottom: 10px; }
    .artist-facts-quote p { margin: 0 0 3px; font-size: .84rem; }
    .artist-facts-quote span { color: var(--muted); font-size: .68rem; }
    .artist-facts details { margin-top: 20px; }
    .artist-facts details summary { cursor: pointer; color: var(--gold); font-size: .76rem; }
    .artist-facts pre { white-space: pre-wrap; font-size: .7rem; background: var(--bg); padding: 12px; border-radius: 8px; max-height: 320px; overflow: auto; }
    .artist-facts-empty { color: var(--muted); font-size: .82rem; }
</style>

<div class="artist-facts">
    <div class="artist-facts-head">
        <div>
            <h2>{{ $facts['display_name'] ?? 'Artista' }}</h2>
            <p>
                {{ trim($city . ($state !== '' ? '/' . $state : '')) ?: 'Cidade não informada' }}
                @if ($studio !== '') · {{ $studio }} @endif
                @if ($source !== '') · fonte: {{ $source }} @endif
            </p>
            <div class="artist-facts-links">
                @if ($landingUrl !== '')
                    <a href="{{ $landingUrl }}" target="_blank" rel="noopener noreferrer">Abrir landing page ↗</a>
                @endif
                @if ($instagramUrl !== '')
                    <a href="{{ $instagramUrl }}" target="_blank" rel="noopener noreferrer">Instagram ↗</a>
                @endif
                @if ($whatsappUrl !== '')
                    <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer">WhatsApp ↗</a>
                @endif
            </div>
        </div>
        <div class="artist-facts-badges">
            @if (! empty($facts['specialty']))
                <span class="artist-facts-badge">{{ $facts['specialty'] }}</span>
            @endif
            @if (! empty($facts['price_range']))
                <span class="artist-facts-badge">{{ $facts['price_range'] }}</span>
            @endif
            @if (! empty($facts['updated_at']))
                <span class="artist-facts-badge">atualizado {{ \Illuminate\Support\Carbon::parse($facts['updated_at'])->format('d/m/Y') }}</span>
            @endif
        </div>
    </div>

    <div class="artist-facts-body">
        <div class="artist-facts-grid">
            <div class="artist-facts-item">
                <span>Estilos</span>
                <strong>{{ $styleSummary ?: '—' }}</strong>
            </div>
            <div class="artist-facts-item">
                <span>Endereço</span>
                <strong>{{ trim($address . ($neighborhood !== '' ? ' — ' . $neighborhood : '')) ?: '—' }}</strong>
            </div>
            <div class="artist-facts-item">
                <span>CEP</span>
                <strong>{{ $zip ?: '—' }}</strong>
            </div>
            <div class="artist-facts-item">
                <span>Experiência</span>
                <strong>{{ ! empty($facts['years_experience']) ? $facts['years_experience'] . ' anos' : '—' }}</strong>
            </div>
            <div class="artist-facts-item">
                <span>WhatsApp</span>
                <strong>{{ $facts['whatsapp_number'] ?? '—' }}</strong>
            </div>
            <div class="artist-facts-item">
                <span>Horário</span>
                <strong>
                    @if ($hours && ! empty($hours['days']))
                        {{ implode(', ', $hours['days']) }} · {{ $hours['opens'] ?? '' }}–{{ $hours['closes'] ?? '' }}
                    @else
                        —
                    @endif
                </strong>
            </div>
        </div>

        @if ($bio !== '')
            <div class="artist-facts-section">
                <h3>Bio pública</h3>
                <p>{!! nl2br(e($bio)) !!}</p>
            </div>
        @endif

        @if ($stats !== [])
            <div class="artist-facts-section">
                <h3>Números</h3>
                <div class="artist-facts-grid">
                    @foreach ($stats as $stat)
                        <div class="artist-facts-item">
                            <span>{{ $stat['label'] ?? '' }}</span>
                            <strong>{{ $stat['value'] ?? '' }}</strong>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($portfolio !== [])
            <div class="artist-facts-section">
                <h3>Portfólio</h3>
                <div class="artist-facts-media">
                    @foreach ($portfolio as $item)
                        <img src="{{ $absolute((string) ($item['src'] ?? '')) }}" alt="{{ $item['alt'] ?? 'Tatuagem' }}" loading="lazy">
                    @endforeach
                </div>
            </div>
        @endif

        @if ($beforeAfter !== [])
            <div class="artist-facts-section">
                <h3>Antes e depois</h3>
                @foreach ($beforeAfter as $item)
                    <div class="artist-facts-pair" style="margin-bottom:12px;">
                        <figure>
                            <img src="{{ $absolute((string) ($item['before'] ?? '')) }}" alt="Antes: {{ $item['title'] ?? '' }}" loading="lazy" style="width:100%;border-radius:8px;">
                            <figcaption>Antes</figcaption>
                        </figure>
                        <figure>
                            <img src="{{ $absolute((string) ($item['after'] ?? '')) }}" alt="Depois: {{ $item['title'] ?? '' }}" loading="lazy" style="width:100%;border-radius:8px;">
                            <figcaption>Depois</figcaption>
                        </figure>
                    </div>
                    @if (! empty($item['title']) || ! empty($item['caption']))
                        <p class="meta" style="margin-top:-6px;">{{ $item['title'] ?? '' }}{{ ! empty($item['caption']) ? ' — ' . $item['caption'] : '' }}</p>
                    @endif
                @endforeach
            </div>
        @endif

        @if ($testimonials !== [])
            <div class="artist-facts-section">
                <h3>Depoimentos</h3>
                @foreach ($testimonials as $testimonial)
                    <div class="artist-facts-quote">
                        <p>“{{ $testimonial['text'] ?? '' }}”</p>
                        <span>
                            {{ $testimonial['name'] ?? 'Cliente' }}
                            @if (! empty($testimonial['meta'])) · {{ $testimonial['meta'] }} @endif
                            @if (! empty($testimonial['rating'])) · {{ $testimonial['rating'] }}/5 @endif
                        </span>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($faq !== [])
            <div class="artist-facts-section">
                <h3>FAQ pública</h3>
                @foreach ($faq as $item)
                    <div style="margin-bottom:10px;">
                        <strong style="font-size:.84rem;">{{ $item['question'] ?? '' }}</strong>
                        <p style="margin:3px 0 0;">{{ $item['answer'] ?? '' }}</p>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($bio === '' && $portfolio === [] && $testimonials === [] && $faq === [])
            <p class="artist-facts-empty">A landing page ainda não tem bio, portfólio ou depoimentos suficientes.</p>
        @endif

        <details>
            <summary>JSON usado pelo blog</summary>
            <pre>{{ json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        </details>
    </div>
</div>
