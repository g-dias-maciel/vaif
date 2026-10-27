/**
 * Artist intake / edit form behavior.
 *
 * - Adds/removes repeatable rows (portfolio, cover-ups, stats, testimonials, FAQ).
 * - Toggles the cover-up block on/off with the "has_coberturas" checkbox.
 * - Shows the chosen filename next to each file input.
 * - Submits multipart data to /api/artists/intake.php via fetch and renders the
 *   inline result (confirmation on create, success banner on edit).
 */
(function () {
    'use strict';

    var form = document.getElementById('intake-form');
    if (!form) {
        return;
    }

    var statusEl = document.getElementById('form-status');
    var submitBtn = document.getElementById('intake-submit');
    var submitLabel = submitBtn ? submitBtn.textContent : 'Enviar';

    // ---- Repeatable rows ------------------------------------------------

    function clearRow(row) {
        row.querySelectorAll('input[type=text], input[type=time]').forEach(function (el) {
            el.value = '';
        });
        row.querySelectorAll('textarea').forEach(function (el) {
            el.value = '';
        });
        row.querySelectorAll('select').forEach(function (el) {
            el.selectedIndex = 0;
        });
        row.querySelectorAll('input[type=file]').forEach(function (el) {
            el.value = '';
        });
        row.querySelectorAll('input[type=hidden]').forEach(function (el) {
            // Clear *_current[] hidden inputs so a new row starts fresh.
            if (el.name && el.name.indexOf('_current') !== -1) {
                el.value = '';
            }
        });
        row.querySelectorAll('.current-file, .file-name').forEach(function (el) {
            el.remove();
        });
    }

    document.querySelectorAll('.repeat').forEach(function (container) {
        var rows = container.querySelector('.repeat-rows');
        var addBtn = container.querySelector('.add-row');
        if (addBtn && rows) {
            addBtn.addEventListener('click', function () {
                var source = rows.querySelector('.repeat-row');
                if (!source) {
                    return;
                }
                var clone = source.cloneNode(true);
                clearRow(clone);
                rows.appendChild(clone);
            });
        }
    });

    // Delegated removal (works for rows added dynamically too).
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.row-remove') : null;
        if (!btn) {
            return;
        }
        var container = btn.closest('.repeat');
        var rows = container ? container.querySelector('.repeat-rows') : null;
        var row = btn.closest('.repeat-row');
        if (rows && row) {
            var all = rows.querySelectorAll('.repeat-row');
            if (all.length > 1) {
                row.remove();
            } else {
                clearRow(row);
            }
        }
    });

    // ---- Cover-up block toggle ------------------------------------------

    var coverCheck = form.querySelector('input[name="has_coberturas"]');
    var baContainer = form.querySelector('.repeat[data-repeat="ba"]');

    function syncCoverToggle() {
        if (!coverCheck || !baContainer) {
            return;
        }
        var on = coverCheck.checked;
        baContainer.classList.toggle('is-disabled', !on);
        baContainer.querySelectorAll('input, textarea, select').forEach(function (el) {
            if (el === coverCheck) {
                return;
            }
            el.disabled = !on;
        });
    }

    if (coverCheck) {
        coverCheck.addEventListener('change', syncCoverToggle);
    }
    syncCoverToggle();

    // ---- File name display ----------------------------------------------

    form.addEventListener('change', function (e) {
        var input = e.target;
        if (input && input.matches && input.matches('input[type=file]')) {
            var label = input.parentElement.querySelector('.file-name');
            if (!label) {
                label = document.createElement('span');
                label.className = 'file-name';
                input.after(label);
            }
            label.textContent = input.files && input.files[0] ? input.files[0].name : '';
        }
    });

    // ---- Status helpers --------------------------------------------------

    function showStatus(type, html) {
        statusEl.hidden = false;
        statusEl.className = 'form-status form-status--' + type;
        statusEl.innerHTML = html;
        statusEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function showError(msg) {
        var div = document.createElement('div');
        div.textContent = msg;
        showStatus('error', '');
        statusEl.appendChild(div);
        statusEl.hidden = false;
    }

    function showSuccess(data) {
        var preview = data.preview_url || '';
        var html = '<strong>Alterações salvas.</strong>';
        if (preview) {
            html += ' <a href="' + preview + '" target="_blank" rel="noopener">Ver prévia</a>';
        }
        showStatus('success', html);
    }

    function showCreateSuccess() {
        var wrap = document.createElement('div');
        wrap.className = 'intake-confirmation';
        wrap.innerHTML =
            '<div class="confirm-icon">&#10003;</div>' +
            '<h2>Recebemos suas informações!</h2>' +
            '<p>Nossa equipe vai montar sua página, revisar o conteúdo e publicar. ' +
            'Se precisarmos de algo, entraremos em contato.</p>' +
            '<a href="/" class="btn-submit">Voltar ao site</a>';
        form.replaceWith(wrap);
    }

    // ---- Submit ----------------------------------------------------------

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        // Basic required-field validation.
        var missing = form.querySelector('[required]');
        if (missing) {
            var name = missing.getAttribute('name');
            if (!missing.value || !missing.value.trim()) {
                showError('Preencha os campos obrigatórios marcados com * antes de enviar.');
                missing.focus();
                return;
            }
        }

        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Enviando...';
        }

        var fd = new FormData(form);

        fetch(form.dataset.action, {
            method: 'POST',
            body: fd
        })
            .then(function (res) {
                return res.json().catch(function () {
                    return { success: false, error: 'Resposta inválida do servidor.' };
                });
            })
            .then(function (data) {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = submitLabel;
                }
                if (data && data.success) {
                    if (form.dataset.mode === 'create') {
                        showCreateSuccess();
                    } else {
                        showSuccess(data);
                    }
                } else {
                    showError((data && data.error) || 'Erro ao enviar. Tente novamente.');
                }
            })
            .catch(function () {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = submitLabel;
                }
                showError('Falha de conexão. Verifique sua internet e tente novamente.');
            });
    });
})();
