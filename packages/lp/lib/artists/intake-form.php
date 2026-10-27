<?php

declare(strict_types=1);

/**
 * Shared artist intake / edit form.
 *
 * Used by the public intake page (create) and the admin edit view (update).
 * Both submit multipart data to /api/artists/intake.php; the JS in
 * novo-artista/form.js handles the fetch + inline errors + confirmation.
 *
 * $values is the artist config array (empty for create).
 */

require_once __DIR__ . '/store.php';

function intake_value(array $values, string $key, string $default = ''): string
{
    $v = $values[$key] ?? $default;
    if (is_array($v)) {
        $v = '';
    }
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/**
 * Render the full intake form.
 */
function render_artist_intake_form(array $values, string $mode, int $id = 0, string $adminToken = '', string $intakeToken = ''): string
{
    $isEdit = $mode === 'edit';
    $fv = fn(string $key, string $default = '') => intake_value($values, $key, $default);

    // Nested config shapes.
    $loc = $values['location'] ?? [];
    $hours = $values['opening_hours'] ?? [];
    $locFv = fn(string $key, string $default = '') => intake_value($loc, $key, $default);

    // ---- Precomputed scalar values (safe to interpolate into the heredoc) ----
    $displayName    = $fv('display_name');
    $priceRange     = $fv('price_range');
    $instagramHandle = $fv('instagram_handle');
    $whatsappNumber = $fv('whatsapp_number');
    $style          = $fv('style');
    $heroHeadline   = $fv('hero_headline');
    $heroSubheadline = $fv('hero_subheadline');
    $anosExp        = $fv('anos_de_experiencia');
    $ctaText        = $fv('cta_text', 'Agende sua sessão pelo WhatsApp');
    $bioText        = htmlspecialchars(artist_bio_to_plain($values['bio'] ?? ''), ENT_QUOTES, 'UTF-8');
    $studioName     = $locFv('studio_name');
    $city           = $locFv('city');
    $street         = $locFv('street');
    $neighborhood   = $locFv('neighborhood');
    $state          = $locFv('state');
    $zip            = $locFv('zip');
    $mapsEmbedUrl   = $locFv('maps_embed_url');
    $openingOpens   = intake_value($hours, 'opens', '10:00');
    $openingCloses  = intake_value($hours, 'closes', '19:00');
    $selectedDays   = $hours['days'] ?? [];
    $hasCoberturas  = ($values['specialty'] ?? '') === 'coberturas';
    $hasCoberturasChecked = $hasCoberturas ? ' checked' : '';

    $action = '/api/artists/intake.php';
    $hidden = $isEdit
        ? '<input type="hidden" name="mode" value="update">'
          . '<input type="hidden" name="id" value="' . $id . '">'
          . '<input type="hidden" name="admin_token" value="' . htmlspecialchars($adminToken, ENT_QUOTES, 'UTF-8') . '">'
        : '<input type="hidden" name="mode" value="create">'
          . '<input type="hidden" name="intake_token" value="' . htmlspecialchars($intakeToken, ENT_QUOTES, 'UTF-8') . '">';

    $submitLabel = $isEdit ? 'Salvar alterações' : 'Enviar minhas informações';
    $formTitle = $isEdit ? 'Editar página do artista' : 'Criar minha página';

    // ---- Repeatable: portfolio ----
    $portfolioRows = '';
    foreach ($values['portfolio'] ?? [] as $item) {
        $src = is_array($item) ? ($item['src'] ?? '') : (string) $item;
        $alt = is_array($item) ? ($item['alt'] ?? '') : '';
        $currentLabel = $src !== '' ? '<span class="current-file">Atual: ' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '</span>' : '';
        $portfolioRows .= '
                <div class="repeat-row">
                    ' . $currentLabel . '
                    <input type="file" name="portfolio_file[]" accept="image/jpeg,image/png,image/webp">
                    <input type="hidden" name="portfolio_current[]" value="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '">
                    <input type="text" name="portfolio_alt[]" placeholder="Legenda (ex.: Realismo leão no antebraço)" value="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '">
                    <button type="button" class="row-remove" aria-label="Remover">&#10005;</button>
                </div>';
    }
    if ($portfolioRows === '') {
        $portfolioRows = '
                <div class="repeat-row">
                    <input type="file" name="portfolio_file[]" accept="image/jpeg,image/png,image/webp">
                    <input type="hidden" name="portfolio_current[]" value="">
                    <input type="text" name="portfolio_alt[]" placeholder="Legenda (ex.: Realismo leão no antebraço)">
                    <button type="button" class="row-remove" aria-label="Remover">&#10005;</button>
                </div>';
    }

    // ---- Repeatable: before/after ----
    $baRows = '';
    foreach ($values['before_after']['items'] ?? [] as $item) {
        $before = $item['before'] ?? '';
        $after = $item['after'] ?? '';
        $title = $item['title'] ?? '';
        $caption = $item['caption'] ?? '';
        $baRows .= '
                <div class="repeat-row ba-row">
                    <label class="mini"><span>Antes</span><input type="file" name="ba_before_file[]" accept="image/jpeg,image/png,image/webp"></label>
                    <input type="hidden" name="ba_before_current[]" value="' . htmlspecialchars($before, ENT_QUOTES, 'UTF-8') . '">
                    <label class="mini"><span>Depois</span><input type="file" name="ba_after_file[]" accept="image/jpeg,image/png,image/webp"></label>
                    <input type="hidden" name="ba_after_current[]" value="' . htmlspecialchars($after, ENT_QUOTES, 'UTF-8') . '">
                    <input type="text" name="ba_title[]" placeholder="Título (ex.: Leão realista)" value="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '">
                    <input type="text" name="ba_caption[]" placeholder="Descrição curta" value="' . htmlspecialchars($caption, ENT_QUOTES, 'UTF-8') . '">
                    <button type="button" class="row-remove" aria-label="Remover">&#10005;</button>
                </div>';
    }
    if ($baRows === '') {
        $baRows = '
                <div class="repeat-row ba-row">
                    <label class="mini"><span>Antes</span><input type="file" name="ba_before_file[]" accept="image/jpeg,image/png,image/webp"></label>
                    <input type="hidden" name="ba_before_current[]" value="">
                    <label class="mini"><span>Depois</span><input type="file" name="ba_after_file[]" accept="image/jpeg,image/png,image/webp"></label>
                    <input type="hidden" name="ba_after_current[]" value="">
                    <input type="text" name="ba_title[]" placeholder="Título (ex.: Leão realista)">
                    <input type="text" name="ba_caption[]" placeholder="Descrição curta">
                    <button type="button" class="row-remove" aria-label="Remover">&#10005;</button>
                </div>';
    }

    // ---- Repeatable: stats ----
    $statRows = '';
    foreach ($values['stats'] ?? [] as $stat) {
        $statRows .= '
                <div class="repeat-row repeat-row--2">
                    <input type="text" name="stat_value[]" placeholder="Valor (ex.: 20+)" value="' . htmlspecialchars((string) ($stat['value'] ?? ''), ENT_QUOTES, 'UTF-8') . '">
                    <input type="text" name="stat_label[]" placeholder="Rótulo (ex.: Anos de Experiência)" value="' . htmlspecialchars((string) ($stat['label'] ?? ''), ENT_QUOTES, 'UTF-8') . '">
                    <button type="button" class="row-remove" aria-label="Remover">&#10005;</button>
                </div>';
    }
    if ($statRows === '') {
        $statRows = '
                <div class="repeat-row repeat-row--2">
                    <input type="text" name="stat_value[]" placeholder="Valor (ex.: 20+)">
                    <input type="text" name="stat_label[]" placeholder="Rótulo (ex.: Anos de Experiência)">
                    <button type="button" class="row-remove" aria-label="Remover">&#10005;</button>
                </div>';
    }

    // ---- Repeatable: testimonials ----
    $testimonialRows = '';
    foreach ($values['testimonials'] ?? [] as $t) {
        $testimonialRows .= '
                <div class="repeat-row testimonial-row">
                    <input type="text" name="testimonial_name[]" placeholder="Nome do cliente" value="' . htmlspecialchars((string) ($t['name'] ?? ''), ENT_QUOTES, 'UTF-8') . '">
                    <input type="text" name="testimonial_meta[]" placeholder="Meta (ex.: Cliente desde 2025)" value="' . htmlspecialchars((string) ($t['meta'] ?? ''), ENT_QUOTES, 'UTF-8') . '">
                    <textarea name="testimonial_text[]" placeholder="Depoimento" rows="2">' . htmlspecialchars((string) ($t['text'] ?? ''), ENT_QUOTES, 'UTF-8') . '</textarea>
                    <select name="testimonial_rating[]">' . intake_rating_options((string) ($t['rating'] ?? '5')) . '</select>
                    <button type="button" class="row-remove" aria-label="Remover">&#10005;</button>
                </div>';
    }
    if ($testimonialRows === '') {
        $testimonialRows = '
                <div class="repeat-row testimonial-row">
                    <input type="text" name="testimonial_name[]" placeholder="Nome do cliente">
                    <input type="text" name="testimonial_meta[]" placeholder="Meta (ex.: Cliente desde 2025)">
                    <textarea name="testimonial_text[]" placeholder="Depoimento" rows="2"></textarea>
                    <select name="testimonial_rating[]">' . intake_rating_options('5') . '</select>
                    <button type="button" class="row-remove" aria-label="Remover">&#10005;</button>
                </div>';
    }

    // ---- Repeatable: FAQ ----
    $faqRows = '';
    foreach ($values['faq'] ?? [] as $f) {
        $faqRows .= '
                <div class="repeat-row faq-row">
                    <input type="text" name="faq_question[]" placeholder="Pergunta" value="' . htmlspecialchars((string) ($f['question'] ?? ''), ENT_QUOTES, 'UTF-8') . '">
                    <textarea name="faq_answer[]" placeholder="Resposta" rows="2">' . htmlspecialchars((string) ($f['answer'] ?? ''), ENT_QUOTES, 'UTF-8') . '</textarea>
                    <button type="button" class="row-remove" aria-label="Remover">&#10005;</button>
                </div>';
    }
    if ($faqRows === '') {
        $faqRows = '
                <div class="repeat-row faq-row">
                    <input type="text" name="faq_question[]" placeholder="Pergunta">
                    <textarea name="faq_answer[]" placeholder="Resposta" rows="2"></textarea>
                    <button type="button" class="row-remove" aria-label="Remover">&#10005;</button>
                </div>';
    }

    // ---- Instagram posts (raw text, escaped for textarea) ----
    $instagramPostsRaw = '';
    foreach ($values['instagram_posts'] ?? [] as $post) {
        $u = is_array($post) ? ($post['url'] ?? '') : (string) $post;
        if ($u !== '') {
            $instagramPostsRaw .= $u . "\n";
        }
    }
    $instagramPosts = htmlspecialchars($instagramPostsRaw, ENT_QUOTES, 'UTF-8');

    // ---- Opening days checkboxes ----
    $daysOptions = '';
    foreach (['Monday' => 'Segunda', 'Tuesday' => 'Terça', 'Wednesday' => 'Quarta', 'Thursday' => 'Quinta', 'Friday' => 'Sexta', 'Saturday' => 'Sábado'] as $dayVal => $dayLabel) {
        $checked = in_array($dayVal, $selectedDays, true) ? ' checked' : '';
        $daysOptions .= '<label class="day-check"><input type="checkbox" name="opening_days[]" value="' . $dayVal . '"' . $checked . '> ' . $dayLabel . '</label>';
    }

    $submitterName = $fv('submitter_name');
    $submitterContact = $fv('submitter_contact');
    $submitterBlock = '';
    if (!$isEdit) {
        $submitterBlock = '
            <div class="form-grid form-grid--2">
                <label class="field">
                    <span class="field-label">Seu nome</span>
                    <input type="text" name="submitter_name" value="' . $submitterName . '" placeholder="Quem está preenchendo?">
                </label>
                <label class="field">
                    <span class="field-label">Seu contato (WhatsApp ou e-mail)</span>
                    <input type="text" name="submitter_contact" value="' . $submitterContact . '" placeholder="Para falarmos com você">
                </label>
            </div>';
    }

    return <<<HTML
<form id="intake-form" class="intake-form" data-mode="{$mode}" data-action="{$action}" novalidate>
    {$hidden}
    <header class="intake-hero">
        <p class="intake-kicker">VAIF &middot; Artistas de Elite</p>
        <h1>{$formTitle}</h1>
        <p class="intake-sub">Preencha com suas informações. Nós montamos sua página e revisamos antes de publicar.</p>
    </header>

    <div id="form-status" class="form-status" role="alert" hidden></div>

    <section class="form-section">
        <h2 class="form-section-title"><span>1</span> Identidade</h2>
        <div class="form-grid form-grid--2">
            <label class="field">
                <span class="field-label">Nome do artista <em>*</em></span>
                <input type="text" name="display_name" value="{$displayName}" placeholder="Ex.: Adriano Santos" required>
            </label>
            <label class="field">
                <span class="field-label">Instagram <em>*</em></span>
                <div class="input-prefix"><span>@</span><input type="text" name="instagram_handle" value="{$instagramHandle}" placeholder="seu.estudio" required></div>
            </label>
            <label class="field">
                <span class="field-label">WhatsApp <em>*</em> (com DDI)</span>
                <input type="text" name="whatsapp_number" value="{$whatsappNumber}" placeholder="Ex.: 5535999999999" inputmode="numeric" required>
            </label>
            <label class="field">
                <span class="field-label">Faixa de preço</span>
                <input type="text" name="price_range" value="{$priceRange}" placeholder="Ex.: R\$ 1.200+">
            </label>
        </div>
        <label class="field">
            <span class="field-label">Estilos de tatuagem <em>*</em></span>
            <input type="text" name="style" value="{$style}" placeholder="Ex.: Realismo Preto &amp; Cinza, Coberturas, Fine Line" required>
            <span class="field-hint">Separe por vírgula. O primeiro estilo vira o destaque da página.</span>
        </label>
    </section>

    <section class="form-section">
        <h2 class="form-section-title"><span>2</span> Chamada principal (herói)</h2>
        <div class="form-grid form-grid--2">
            <label class="field">
                <span class="field-label">Título principal</span>
                <input type="text" name="hero_headline" value="{$heroHeadline}" placeholder="Deixe em branco para gerar automático">
            </label>
            <label class="field">
                <span class="field-label">Subtítulo</span>
                <input type="text" name="hero_subheadline" value="{$heroSubheadline}" placeholder="Uma frase sobre seu trabalho">
            </label>
        </div>
    </section>

    <section class="form-section">
        <h2 class="form-section-title"><span>3</span> Fotos</h2>
        <div class="form-grid form-grid--2">
            <label class="field">
                <span class="field-label">Foto principal (perfil / capa)</span>
                <input type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp">
                <span class="field-hint">JPG, PNG ou WebP, até 15 MB.</span>
            </label>
            <label class="field">
                <span class="field-label">Foto "sobre" (opcional)</span>
                <input type="file" name="about_photo" accept="image/jpeg,image/png,image/webp">
                <span class="field-hint">Sua foto no estúdio ou retrato.</span>
            </label>
        </div>
    </section>

    <section class="form-section">
        <h2 class="form-section-title"><span>4</span> Portfólio</h2>
        <p class="form-intro">Adicione fotos dos seus melhores trabalhos. Quanto mais, melhor.</p>
        <div class="repeat" data-repeat="portfolio">
            <div class="repeat-rows">{$portfolioRows}</div>
            <button type="button" class="add-row">+ Adicionar trabalho</button>
        </div>
    </section>

    <section class="form-section">
        <h2 class="form-section-title"><span>5</span> Coberturas (Antes e Depois)</h2>
        <label class="check">
            <input type="checkbox" name="has_coberturas" value="1"{$hasCoberturasChecked}>
            <span>Trabalho com coberturas (mostrar bloco Antes/Depois)</span>
        </label>
        <div class="repeat" data-repeat="ba">
            <div class="repeat-rows">{$baRows}</div>
            <button type="button" class="add-row">+ Adicionar cobertura</button>
        </div>
    </section>

    <section class="form-section">
        <h2 class="form-section-title"><span>6</span> Sobre você</h2>
        <label class="field">
            <span class="field-label">Bio / história</span>
            <textarea name="bio" rows="8" placeholder="Conte sua trajetória, sua técnica, o que te move como tatuador...">{$bioText}</textarea>
            <span class="field-hint">Quebre linhas para criar parágrafos.</span>
        </label>
        <div class="form-grid form-grid--2">
            <label class="field">
                <span class="field-label">Anos de experiência</span>
                <input type="text" name="anos_de_experiencia" value="{$anosExp}" placeholder="Ex.: 10" inputmode="numeric">
            </label>
            <label class="field">
                <span class="field-label">Texto do botão de agendamento</span>
                <input type="text" name="cta_text" value="{$ctaText}">
            </label>
        </div>
        <div class="repeat" data-repeat="stats">
            <p class="repeat-label">Números de destaque (opcional)</p>
            <div class="repeat-rows">{$statRows}</div>
            <button type="button" class="add-row">+ Adicionar número</button>
        </div>
    </section>

    <section class="form-section">
        <h2 class="form-section-title"><span>7</span> Depoimentos</h2>
        <p class="form-intro">Cole avaliações reais de clientes. Deixe em branco se não tiver ainda.</p>
        <div class="repeat" data-repeat="testimonials">
            <div class="repeat-rows">{$testimonialRows}</div>
            <button type="button" class="add-row">+ Adicionar depoimento</button>
        </div>
    </section>

    <section class="form-section">
        <h2 class="form-section-title"><span>8</span> Instagram</h2>
        <label class="field">
            <span class="field-label">Links de posts/reels (um por linha)</span>
            <textarea name="instagram_posts" rows="4" placeholder="https://www.instagram.com/p/...&#10;https://www.instagram.com/reel/...">{$instagramPosts}</textarea>
            <span class="field-hint">Se deixar em branco, mostramos seu perfil @{$instagramHandle} com um convite para seguir.</span>
        </label>
    </section>

    <section class="form-section">
        <h2 class="form-section-title"><span>9</span> FAQ (dúvidas frequentes)</h2>
        <p class="form-intro">Perguntas que seus clientes sempre fazem. Se deixar em branco, usamos um padrão.</p>
        <div class="repeat" data-repeat="faq">
            <div class="repeat-rows">{$faqRows}</div>
            <button type="button" class="add-row">+ Adicionar pergunta</button>
        </div>
    </section>

    <section class="form-section">
        <h2 class="form-section-title"><span>10</span> Localização</h2>
        <div class="form-grid form-grid--2">
            <label class="field">
                <span class="field-label">Nome do estúdio</span>
                <input type="text" name="studio_name" value="{$studioName}" placeholder="Ex.: Studio Madri Tattoo">
            </label>
            <label class="field">
                <span class="field-label">Cidade</span>
                <input type="text" name="city" value="{$city}" placeholder="Ex.: Poços de Caldas">
            </label>
            <label class="field">
                <span class="field-label">Rua / endereço</span>
                <input type="text" name="street" value="{$street}" placeholder="Ex.: Rua Osvaldo Cruz, 135">
            </label>
            <label class="field">
                <span class="field-label">Bairro</span>
                <input type="text" name="neighborhood" value="{$neighborhood}" placeholder="Ex.: Jardim Santa Rita">
            </label>
            <label class="field">
                <span class="field-label">Estado (UF)</span>
                <input type="text" name="state" value="{$state}" placeholder="Ex.: MG" maxlength="2">
            </label>
            <label class="field">
                <span class="field-label">CEP</span>
                <input type="text" name="zip" value="{$zip}" placeholder="Ex.: 37701-161">
            </label>
        </div>
        <label class="field">
            <span class="field-label">Link do Google Maps (embed)</span>
            <input type="text" name="maps_embed_url" value="{$mapsEmbedUrl}" placeholder="Cole o link de incorporação do Google Maps">
        </label>
        <div class="form-grid form-grid--2">
            <label class="field">
                <span class="field-label">Abre às</span>
                <input type="time" name="opening_opens" value="{$openingOpens}">
            </label>
            <label class="field">
                <span class="field-label">Fecha às</span>
                <input type="time" name="opening_closes" value="{$openingCloses}">
            </label>
        </div>
        <div class="field">
            <span class="field-label">Dias de atendimento</span>
            <div class="day-checks">{$daysOptions}</div>
        </div>
    </section>

    {$submitterBlock}

    <div class="intake-actions">
        <button type="submit" class="btn-submit" id="intake-submit">{$submitLabel}</button>
        <p class="intake-note">Ao enviar, você concorda com o uso das informações para criar sua página. Nós revisamos tudo antes de publicar.</p>
    </div>
</form>
HTML;
}

/**
 * 1–5 star options for a testimonial rating select.
 */
function intake_rating_options(string $selected): string
{
    $sel = (int) $selected;
    $out = '';
    for ($i = 5; $i >= 1; $i--) {
        $checked = $i === $sel ? ' selected' : '';
        $out .= '<option value="' . $i . '"' . $checked . '>' . $i . ' estrela' . ($i > 1 ? 's' : '') . '</option>';
    }
    return $out;
}
