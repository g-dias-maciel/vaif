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

<div class="panel" style="margin-bottom: 24px;">
    <h2 class="section-title" style="margin-top:0;">Sinal e PIX</h2>
    <p class="meta">A Beatriz usa esta chave quando o lead escolhe pagar o sinal.</p>

    <form method="post" action="{{ route('settings.payment') }}" style="margin-top:12px;">
        @csrf
        <div class="form-field" style="max-width:520px;">
            <label for="pix-key">Chave PIX</label>
            <input type="text" name="pix_key" id="pix-key"
                value="{{ old('pix_key', $artist->settings?->pix_key) }}"
                placeholder="CPF/CNPJ, e-mail, telefone ou chave aleatória"
                maxlength="255">
            @error('pix_key')
                <span class="meta" style="color: var(--danger);">{{ $message }}</span>
            @enderror
        </div>

        @php
            $currentDepositType = $artist->settings?->deposit_type;
            $currentDepositValue = $artist->settings?->deposit_value;
        @endphp
        <div class="form-field" style="max-width:220px; margin-top:12px;">
            <label for="deposit-percentage">Sinal (%)</label>
            <input type="number" name="deposit_percentage" id="deposit-percentage"
                value="{{ old('deposit_percentage', $currentDepositType === 'percent' ? $currentDepositValue : '') }}"
                min="1" max="100" step="0.5" required>
            <span class="meta">Percentual do valor à vista. Ex.: 30 para 30%.</span>
            @if ($currentDepositType === 'fixed')
                <span class="meta" style="color: var(--gold);">
                    O sinal atual é um valor fixo (R$ {{ number_format((float) $currentDepositValue, 2, ',', '.') }}).
                    Salvar aqui passará a usar percentual.
                </span>
            @endif
            @error('deposit_percentage')
                <span class="meta" style="color: var(--danger);">{{ $message }}</span>
            @enderror
        </div>

        <button type="submit" class="btn btn--small" style="margin-top:10px;">Salvar sinal e PIX</button>
    </form>

    @if (blank($artist->settings?->pix_key))
        <p class="meta" style="margin-top:10px;">
            Sem chave PIX configurada, a Beatriz não vai pedir o sinal; ela passa o atendimento para você.
        </p>
    @endif
</div>

<div class="panel" style="margin-bottom: 24px;">
    <h2 class="section-title" style="margin-top:0;">Negociação</h2>
    @php
        $maxDiscount = $artist->settings?->floor_pct !== null
            ? 100 - (float) $artist->settings->floor_pct
            : 20;
    @endphp
    <p class="meta">
        A Beatriz pode negociar até este desconto. Acima dele, ela passa o atendimento para você.
    </p>

    <form method="post" action="{{ route('settings.negotiation') }}" style="margin-top:12px;">
        @csrf
        <div class="form-field" style="max-width:220px;">
            <label for="max-discount">Desconto máximo (%)</label>
            <input type="number" name="max_discount" id="max-discount"
                value="{{ old('max_discount', number_format($maxDiscount, 2, '.', '')) }}"
                min="0" max="100" step="0.01">
            @error('max_discount')
                <span class="meta" style="color: var(--danger);">{{ $message }}</span>
            @enderror
        </div>
        <button type="submit" class="btn btn--small" style="margin-top:10px;">Salvar desconto máximo</button>
    </form>
</div>

@php
    $fullSelling = $artist->settings?->full_selling ?? true;
@endphp
<div class="panel" style="margin-bottom: 24px;">
    <h2 class="section-title" style="margin-top:0;">Modo de atendimento</h2>
    <p class="meta">
        Define até onde a Beatriz conduz a conversa. Com a venda completa desativada, ela faz só a triagem:
        coleta os dados da tatuagem e passa o atendimento para você antes de falar de preço e horário.
    </p>

    <form method="post" action="{{ route('settings.selling') }}" style="margin-top:12px;">
        @csrf
        <input type="hidden" name="full_selling" value="0">
        <label for="full-selling" style="display:flex; gap:10px; align-items:flex-start; cursor:pointer;">
            <input type="checkbox" name="full_selling" id="full-selling" value="1"
                @checked($fullSelling) style="margin-top:4px;">
            <span>
                <strong>Venda completa</strong>
                <span class="meta" style="display:block;">
                    Ativa: a Beatriz apresenta preço, negocia, agenda e pede o sinal.<br>
                    Desativada: a Beatriz faz a triagem e te passa o lead para você passar o valor e combinar o horário.
                </span>
            </span>
        </label>
        @error('full_selling')
            <span class="meta" style="color: var(--danger);">{{ $message }}</span>
        @enderror

        <button type="submit" class="btn btn--small" style="margin-top:12px;">Salvar modo de atendimento</button>
    </form>
</div>

<div class="panel" style="margin-bottom: 24px;">
    <h2 class="section-title" style="margin-top:0;">Estúdio</h2>
    <p class="meta">
        A Beatriz usa estes dados quando o lead pergunta onde fica o estúdio. Se o endereço ficar em branco,
        ela avisa que você envia o endereço completo — ela não inventa cidade nem endereço.
    </p>

    <form method="post" action="{{ route('settings.studio') }}" style="margin-top:12px;">
        @csrf
        <div class="form-grid">
            <div class="form-field">
                <label for="studio-city">Cidade</label>
                <input type="text" name="city" id="studio-city"
                    value="{{ old('city', $artist->settings?->city) }}" maxlength="120"
                    placeholder="Ex.: Poços de Caldas">
                @error('city')
                    <span class="meta" style="color: var(--danger);">{{ $message }}</span>
                @enderror
            </div>
            <div class="form-field">
                <label for="studio-address">Endereço completo</label>
                <input type="text" name="address" id="studio-address"
                    value="{{ old('address', $artist->settings?->address) }}" maxlength="255"
                    placeholder="Rua, número, bairro">
                @error('address')
                    <span class="meta" style="color: var(--danger);">{{ $message }}</span>
                @enderror
            </div>
        </div>
        <button type="submit" class="btn btn--small" style="margin-top:10px;">Salvar estúdio</button>
    </form>
</div>

@php
    $oldBlocked = old('blocked_numbers');
    $blockedNumbers = is_string($oldBlocked)
        ? array_filter(array_map('trim', explode(',', $oldBlocked)))
        : ($artist->settings?->blocked_numbers ?? []);
@endphp
<div class="panel" style="margin-bottom: 24px;">
    <h2 class="section-title" style="margin-top:0;">Números bloqueados</h2>
    <p class="meta">
        A Beatriz ignora estes números: não responde, não cria lead e não agenda. Use DDI + DDD + número.
    </p>

    <form method="post" action="{{ route('settings.blocked-numbers') }}" style="margin-top:12px;">
        @csrf
        <div class="form-field" style="max-width:520px;">
            <div class="tag-field" data-tag-field data-name="blocked_numbers">
                <span data-tag-chips style="display:contents;">
                    @foreach ($blockedNumbers as $number)
                        <span class="tag-field__chip" data-value="{{ $number }}">
                            {{ $number }}
                            <button type="button" data-tag-remove aria-label="Remover {{ $number }}">&times;</button>
                        </span>
                    @endforeach
                </span>
                <input type="text" data-tag-entry inputmode="tel" autocomplete="off"
                    placeholder="+55 11 99999-0001">
            </div>
            <input type="hidden" name="blocked_numbers" data-tag-value value="{{ implode(',', $blockedNumbers) }}">
            @error('blocked_numbers')
                <span class="meta" style="color: var(--danger);">{{ $message }}</span>
            @enderror
        </div>
        <button type="submit" class="btn btn--small" style="margin-top:10px;">Salvar números bloqueados</button>
    </form>
</div>

@php
    $oldSpecialties = old('specialties');
    $specialties = is_string($oldSpecialties)
        ? array_filter(array_map('trim', explode(',', $oldSpecialties)))
        : ($artist->settings?->specialties ?? []);

    $oldExcluded = old('excluded_styles');
    $excludedStyles = is_string($oldExcluded)
        ? array_filter(array_map('trim', explode(',', $oldExcluded)))
        : ($artist->settings?->excluded_styles ?? []);
@endphp
<div class="panel" style="margin-bottom: 24px;">
    <h2 class="section-title" style="margin-top:0;">Estilos</h2>
    <p class="meta">
        A Beatriz usa estas listas na conversa: ela conecta o pedido do lead com os estilos que você faz e passa o
        atendimento para você quando o pedido é de um estilo que você não faz.
    </p>

    @if (empty($specialties) && empty($excludedStyles))
        <p class="meta" style="color: var(--gold);">
            Nenhum estilo cadastrado: a Beatriz não filtra por estilo e segue a conversa normalmente.
        </p>
    @endif

    <form method="post" action="{{ route('settings.styles') }}" style="margin-top:12px;">
        @csrf
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:16px;">
            <div>
                <label class="tag-field__label" for="specialties-entry">Estilos que faço</label>
                <div class="tag-field" data-tag-field data-name="specialties">
                    <span data-tag-chips style="display:contents;">
                        @foreach ($specialties as $style)
                            <span class="tag-field__chip" data-value="{{ $style }}">
                                {{ $style }}
                                <button type="button" data-tag-remove aria-label="Remover {{ $style }}">&times;</button>
                            </span>
                        @endforeach
                    </span>
                    <input type="text" id="specialties-entry" data-tag-entry list="style-suggestions"
                        placeholder="Ex.: realismo" autocomplete="off">
                </div>
                <input type="hidden" name="specialties" data-tag-value value="{{ implode(',', $specialties) }}">
                @error('specialties')
                    <span class="meta" style="color: var(--danger);">{{ $message }}</span>
                @enderror
            </div>

            <div>
                <label class="tag-field__label" for="excluded-styles-entry">Estilos que não faço</label>
                <div class="tag-field" data-tag-field data-name="excluded_styles">
                    <span data-tag-chips style="display:contents;">
                        @foreach ($excludedStyles as $style)
                            <span class="tag-field__chip" data-value="{{ $style }}">
                                {{ $style }}
                                <button type="button" data-tag-remove aria-label="Remover {{ $style }}">&times;</button>
                            </span>
                        @endforeach
                    </span>
                    <input type="text" id="excluded-styles-entry" data-tag-entry list="style-suggestions"
                        placeholder="Ex.: rosto, blackwork" autocomplete="off">
                </div>
                <input type="hidden" name="excluded_styles" data-tag-value value="{{ implode(',', $excludedStyles) }}">
                @error('excluded_styles')
                    <span class="meta" style="color: var(--danger);">{{ $message }}</span>
                @enderror
            </div>
        </div>

        <datalist id="style-suggestions">
            @foreach (config('crm.tattoo_styles', []) as $style)
                <option value="{{ $style }}"></option>
            @endforeach
        </datalist>

        <button type="submit" class="btn btn--small" style="margin-top:12px;">Salvar estilos</button>
    </form>
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
    document.querySelectorAll('[data-tag-field]').forEach(function (field) {
        var entry = field.querySelector('[data-tag-entry]');
        var chips = field.querySelector('[data-tag-chips]');
        var hidden = field.parentElement.querySelector('[data-tag-value]');

        if (!entry || !chips || !hidden) {
            return;
        }

        function values() {
            return Array.prototype.map.call(
                chips.querySelectorAll('.tag-field__chip'),
                function (chip) { return chip.dataset.value; }
            );
        }

        function sync() {
            hidden.value = values().join(',');
        }

        function add(raw) {
            var value = String(raw || '').replace(/\s+/g, ' ').trim().replace(/^[.\-\s]+|[.\-\s]+$/g, '');
            if (value === '') {
                return;
            }

            var duplicate = values().some(function (existing) {
                return existing.toLowerCase() === value.toLowerCase();
            });
            if (duplicate) {
                return;
            }

            var chip = document.createElement('span');
            chip.className = 'tag-field__chip';
            chip.dataset.value = value;
            chip.appendChild(document.createTextNode(value + ' '));

            var remove = document.createElement('button');
            remove.type = 'button';
            remove.setAttribute('data-tag-remove', '');
            remove.setAttribute('aria-label', 'Remover ' + value);
            remove.innerHTML = '&times;';
            chip.appendChild(remove);

            chips.appendChild(chip);
            sync();
        }

        entry.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ',') {
                event.preventDefault();
                add(entry.value);
                entry.value = '';
            } else if (event.key === 'Backspace' && entry.value === '') {
                var last = chips.querySelector('.tag-field__chip:last-of-type');
                if (last) {
                    last.remove();
                    sync();
                }
            }
        });

        entry.addEventListener('blur', function () {
            add(entry.value);
            entry.value = '';
        });

        field.addEventListener('click', function (event) {
            var remove = event.target.closest('[data-tag-remove]');
            if (remove) {
                var chip = remove.closest('.tag-field__chip');
                if (chip) {
                    chip.remove();
                    sync();
                }
            }
        });

        sync();
    });
})();
</script>
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
