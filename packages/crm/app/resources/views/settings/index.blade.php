@extends('layouts.app')

@section('title', 'Configuração')

@php
    $stateLabels = [
        'WORKING' => 'Conectado',
        'SCAN_QR_CODE' => 'Aguardando leitura do QR',
        'STARTING' => 'Iniciando',
        'FAILED' => 'Falhou',
        'STOPPED' => 'Parado',
        'UNKNOWN' => 'Desconhecido',
    ];
@endphp

@section('content')
<h1 class="page-title">Configuração — {{ $artist?->displayName() }}</h1>

<div class="panel" style="margin-bottom: 24px;">
    <h2 class="section-title" style="margin-top:0;">Conexão WhatsApp</h2>

    @if (!$artist?->wa_session_slug)
        <p class="meta">Este artista ainda não possui uma sessão WhatsApp configurada.</p>
    @else
        <p class="meta">Sessão: <code>{{ $artist->wa_session_slug }}</code></p>

        @if ($waha === null)
            <div class="flash error" style="display:inline-block;">Status indisponível (WAHA fora de alcance)</div>
        @elseif ($waha['connected'])
            <div class="flash" style="display:inline-block;">&#x25CF; Conectado</div>
            @if ($waha['me'])
                <p class="meta" style="margin-top:8px;">Conta: {{ $waha['me'] }}</p>
            @endif
        @else
            <div class="flash error" style="display:inline-block;">&#x25CF; {{ $stateLabels[$waha['state']] ?? $waha['state'] }}</div>
        @endif

        @if ($qr)
            <div style="margin: 14px 0;">
                <img src="data:image/png;base64,{{ $qr }}" alt="QR Code" style="width: 220px; height: 220px; background:#fff; border-radius: 12px;">
                <p class="meta" style="margin-top:8px;">Escaneie o QR code no WhatsApp do artista para conectar.</p>
            </div>
        @elseif ($waha && !$waha['connected'])
            <p class="meta" style="margin-top:10px;">Preparando o QR code… esta página atualiza automaticamente.</p>
        @endif

        <div style="display:flex; gap:10px; margin-top:14px; flex-wrap:wrap;">
            <form method="post" action="{{ route('settings.waha.connect') }}" class="inline">
                @csrf
                <button type="submit" class="btn">Conectar</button>
            </form>
            <form method="post" action="{{ route('settings.waha.restart') }}" class="inline">
                @csrf
                <button type="submit" class="btn btn--ghost">Reiniciar sessão</button>
            </form>
        </div>
    @endif
</div>

@if ($googleConfigured || auth()->user()->isStaff() || $artist->hasGoogleCalendar())
<div class="panel" style="margin-bottom: 24px;">
    <h2 class="section-title" style="margin-top:0;">Google Agenda</h2>

    @if ($artist->hasGoogleCalendar())
        <div class="flash" style="display:inline-block;">&#x25CF; Conectada</div>
        <p class="meta" style="margin-top:8px;">
            Agenda: <strong>{{ $artist->google_calendar_summary }}</strong>
        </p>
        <p class="meta"><code>{{ $artist->google_calendar_id }}</code></p>
        <p class="meta">
            Conectada em {{ $artist->google_connected_at?->format('d/m/Y H:i') }}
            @if ($artist->google_calendar_timezone)
                · Fuso da agenda Google: {{ $artist->google_calendar_timezone }}
            @endif
        </p>

        @if ($artist->google_calendar_timezone && $artist->google_calendar_timezone !== $artist->timezone)
            <div class="flash error" style="display:block; margin-top:10px;">
                O fuso da Google Agenda ({{ $artist->google_calendar_timezone }}) é diferente do fuso do CRM
                ({{ $artist->timezone }}). Os eventos podem aparecer em outro horário. Ajuste o fuso da agenda
                no Google para {{ $artist->timezone }} e clique em “Sincronizar agora”.
            </div>
        @endif

        <div class="actions-row">
            <form method="post" action="{{ route('settings.google.sync') }}" class="inline">
                @csrf
                <button type="submit" class="btn btn--small">Sincronizar agora</button>
            </form>
            <form method="post" action="{{ route('settings.google.disconnect') }}" class="inline">
                @csrf
                <button type="submit" class="btn btn--ghost btn--small"
                    onclick="return confirm('Desconectar a Google Agenda? Os eventos já criados permanecem na agenda.');">
                    Desconectar
                </button>
            </form>
        </div>

        <p class="meta" style="margin-top:10px;">
            Os agendamentos são sincronizados automaticamente em até 1 minuto. Remarcações e cancelamentos
            atualizam o mesmo evento. Desconectar não apaga os eventos que já estão no Google.
        </p>
    @elseif (! $googleConfigured)
        <p class="meta">A integração com o Google Agenda ainda não foi configurada no servidor.</p>
        @if (auth()->user()->isStaff())
            <p class="meta" style="margin-top:8px;">
                Defina <code>GOOGLE_SERVICE_ACCOUNT_JSON</code> nas variáveis de ambiente do CRM e reinicie o container.
            </p>
        @else
            <p class="meta" style="margin-top:8px;">Fale com a equipe VAIF para ativar a integração.</p>
        @endif
    @else
        <p class="meta">Compartilhe sua agenda com o e-mail da VAIF:</p>

        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin:10px 0 16px;">
            <code id="google-service-email" style="font-size:0.84rem;">{{ $googleServiceEmail }}</code>
            <button type="button" class="btn btn--ghost btn--small"
                onclick="navigator.clipboard.writeText(@js($googleServiceEmail)); this.textContent='Copiado';">
                Copiar
            </button>
        </div>

        <ol class="meta" style="margin:0 0 16px 18px; line-height:1.7;">
            <li>Abra o Google Agenda → Configurações → Configurações da minha agenda.</li>
            <li>Selecione a agenda e clique em “Compartilhar com pessoas específicas”.</li>
            <li>Adicione o e-mail acima com a permissão “Fazer alterações nos eventos”.</li>
            <li>Volte aqui, detecte a agenda compartilhada (ou cole o ID em “Integrar agenda”).</li>
        </ol>

        <form method="post" action="{{ route('settings.google.detect') }}" style="margin-bottom:16px;">
            @csrf
            <button type="submit" class="btn btn--ghost btn--small">Detectar agendas compartilhadas</button>
        </form>

        @if (! empty(session('google_calendars')))
            <form method="post" action="{{ route('settings.google.connect') }}" style="margin-bottom:16px;">
                @csrf
                <div class="form-field" style="max-width:520px;">
                    <label for="detected-calendar">Agendas encontradas</label>
                    <select name="calendar_id" id="detected-calendar" style="background:var(--bg); border:1px solid var(--border); border-radius:8px; color:var(--ink); padding:10px 12px; font-family:'Montserrat', sans-serif;">
                        @foreach (session('google_calendars') as $calendar)
                            <option value="{{ $calendar['id'] }}">
                                {{ $calendar['summary'] }} — {{ $calendar['id'] }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="btn btn--small" style="margin-top:10px;">Conectar agenda</button>
            </form>
        @endif

        <form method="post" action="{{ route('settings.google.connect') }}">
            @csrf
            <div class="form-field" style="max-width:520px;">
                <label for="calendar-id">Integrar agenda pelo ID</label>
                <input type="text" name="calendar_id" id="calendar-id" required
                    placeholder="voce@gmail.com ou xxxx@group.calendar.google.com">
            </div>
            <p class="meta" style="margin:6px 0 0;">
                O ID fica em Google Agenda → Configurações → Integrar agenda → “ID da agenda”.
            </p>
            <button type="submit" class="btn btn--small" style="margin-top:10px;">Conectar agenda</button>
        </form>
    @endif
</div>
@endif

<div class="panel" style="margin-bottom: 24px;">
    <h2 class="section-title" style="margin-top:0;">Promoções</h2>

    @forelse ($promotions as $promo)
        <div class="promo-row {{ $promo->isActive() ? 'is-active' : '' }}">
            <div class="promo-main">
                <strong>{{ $promo->name }}</strong>
                <span class="meta">{{ $promo->multiplierLabel() }} · {{ $promo->audienceLabel() }}</span>
            </div>
            <span class="tag {{ $promo->isActive() ? 'tag-success' : 'tag-muted' }}">{{ $promo->statusLabel() }}</span>
        </div>
    @empty
        <p class="empty">Nenhuma promoção cadastrada para este artista.</p>
    @endforelse

    <p class="meta" style="margin-top: 10px;">As promoções são criadas e ativadas pela equipe VAIF. Aqui você apenas acompanha o status.</p>
</div>

<div class="panel">
    <h2 class="section-title" style="margin-top:0;">Tabela de preços</h2>

    <div class="pricing-tabs" role="tablist">
        @foreach ($regions as $regionKey => $region)
            <button type="button" class="pricing-tab {{ $loop->first ? 'active' : '' }}" data-region="{{ $regionKey }}">{{ $region['label'] }}</button>
        @endforeach
    </div>

    @foreach ($regions as $regionKey => $region)
        <div class="pricing-panel {{ $loop->first ? 'active' : '' }}" data-region="{{ $regionKey }}">
            <form method="post" action="{{ route('pricing.bulk') }}">
                @csrf
                <input type="hidden" name="region" value="{{ $regionKey }}">

                <div class="pricing-matrix">
                    <table>
                        <thead>
                            <tr>
                                <th rowspan="2">Local</th>
                                @foreach ($sizes as $sizeLabel)
                                    <th colspan="2">{{ $sizeLabel }}</th>
                                @endforeach
                            </tr>
                            <tr>
                                @foreach ($sizes as $sizeLabel)
                                    <th class="sub">Preço (R$)</th>
                                    <th class="sub">Duração (min)</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($region['locations'] as $locationKey => $locationLabel)
                                <tr>
                                    <td class="loc-label">{{ $locationLabel }}</td>
                                    @foreach ($sizes as $sizeKey => $sizeLabel)
                                        @php
                                            $cell = $pricingByKey[$locationKey][$sizeKey] ?? null;
                                        @endphp
                                        <td>
                                            <input type="number" step="0.01" min="0"
                                                name="cells[{{ $locationKey }}][{{ $sizeKey }}][price]"
                                                value="{{ $cell ? number_format($cell->base_price_cents / 100, 2, '.', '') : '' }}"
                                                placeholder="0,00" aria-label="Preço (R$) — {{ $sizeLabel }}">
                                        </td>
                                        <td>
                                            <input type="number" min="1"
                                                name="cells[{{ $locationKey }}][{{ $sizeKey }}][duration]"
                                                value="{{ $cell ? $cell->session_duration_min : 120 }}"
                                                placeholder="120" aria-label="Duração (min) — {{ $sizeLabel }}">
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <button type="submit" class="btn btn--small" style="margin-top:14px;">Salvar {{ $region['label'] }}</button>
            </form>
        </div>
    @endforeach

    <div class="pricing-add-row">
        <form method="post" action="{{ route('pricing.add-location') }}" class="pricing-add">
            @csrf
            <select name="region">
                @foreach ($regions as $regionKey => $region)
                    <option value="{{ $regionKey }}">{{ $region['label'] }}</option>
                @endforeach
            </select>
            <input type="text" name="name" placeholder="Novo local (ex: coxa)" required>
            <button type="submit" class="btn btn--small">Adicionar local</button>
        </form>

        <form method="post" action="{{ route('pricing.add-size') }}" class="pricing-add">
            @csrf
            <input type="text" name="name" placeholder="Novo tamanho (ex: extra grande)" required>
            <button type="submit" class="btn btn--small">Adicionar tamanho</button>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var tabs = document.querySelectorAll('.pricing-tab');
    var panels = document.querySelectorAll('.pricing-panel');

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            tabs.forEach(function (t) { t.classList.remove('active'); });
            panels.forEach(function (p) { p.classList.remove('active'); });

            tab.classList.add('active');
            var panel = document.querySelector('.pricing-panel[data-region="' + tab.dataset.region + '"]');
            if (panel) {
                panel.classList.add('active');
            }
        });
    });
})();
</script>
@if ($waha && !$waha['connected'] && !$qr && in_array($waha['state'], ['STARTING', 'SCAN_QR_CODE'], true))
    <script>
        setTimeout(function () { window.location.reload(); }, 4000);
    </script>
@endif
@endpush
