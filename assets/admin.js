(function ($) {
    'use strict';

    var running = false;
    var current = AMF_DATA.status || { exists: false };
    var retryCount = 0;
    var maxRetries = 5;
    var familyBulkBusy = false;
    var performance = $.extend({}, AMF_DATA.performance || {});

    function setButtons() {
        var complete = current && current.exists && current.status === 'complete';
        $('#amf-resume-scan').prop('disabled', running || complete);
        $('#amf-pause-scan').prop('disabled', !running);
        $('#amf-start-scan').prop('disabled', running);
    }

    function defaultMessage(status) {
        if (!status || !status.exists) return '';
        if (status.status === 'complete') return 'Analyse terminée.';
        if (status.status === 'error') return status.message || 'Le dernier lot a rencontré une erreur. Cliquez sur « Reprendre ».';
        if (!running) return 'Progression enregistrée. Cliquez sur « Reprendre » pour continuer.';
        return 'Analyse en cours. Gardez cet onglet ouvert pour la faire avancer.';
    }

    function render(status) {
        if (!status || !status.exists) return;
        current = status;
        var total = parseInt(status.phase_total || 0, 10);
        var done = parseInt(status.phase_current || 0, 10);
        var pct = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : (status.status === 'complete' ? 100 : 12);
        $('#amf-progress').attr('data-scan-id', status.id);
        var phaseNames = { media: 'Inventaire des médias', usage_posts: 'Recherche des utilisations', usage_postmeta: 'Recherche des utilisations', usage_termmeta: 'Recherche des utilisations', usage_options: 'Recherche des utilisations', files: 'Vérification des fichiers', woo: 'Vérification des produits', finalize: 'Finalisation' };
        var phaseLabel = status.status === 'complete' ? 'Analyse terminée' : (phaseNames[status.phase] || 'Analyse en cours');
        var phaseOrder = ['media', 'usage_posts', 'usage_postmeta', 'usage_termmeta', 'usage_options', 'files', 'woo', 'finalize'];
        var phasePosition = phaseOrder.indexOf(status.phase);
        var stepNumber = status.status === 'complete' ? phaseOrder.length : Math.max(1, phasePosition + 1);
        $('#amf-phase-step').text(status.status === 'complete' ? '8/8 étapes terminées' : ' Étape ' + stepNumber + '/' + phaseOrder.length);
        $('#amf-phase-label').text(phaseLabel);
        $('#amf-progress-bar').css('width', pct + '%').toggleClass('amf-indeterminate', total === 0 && status.status !== 'complete' && running);
        $('#amf-progress-track').attr('aria-valuenow', pct).attr('aria-valuetext', phaseLabel + ' : ' + pct + ' %');
        $('#amf-scan-state').text(status.status === 'complete' ? 'Terminée' : (running ? 'En cours' : 'À reprendre'));
        $('#amf-progress-numbers').text(total > 0 ? (done + ' / ' + total) : (done ? done + ' éléments analysés' : ''));
        $('#amf-progress-message').text(defaultMessage(status));
        var batch = parseInt(status.batch_limit || 0, 10);
        var delay = parseInt((performance && performance.request_delay_ms) || status.request_delay_ms || 0, 10);
        var maxTime = parseInt((performance && performance.time_budget_seconds) || status.time_budget_seconds || 0, 10);
        $('#amf-live-performance').text(batch ? ('Cadence actuelle : max ' + batch + ' élément(s) / lot • délai ' + delay + ' ms • temps max ' + maxTime + ' s') : '');
        setButtons();
    }

    function request(action, extra, timeout) {
        var data = $.extend({ action: action, nonce: AMF_DATA.nonce }, extra || {});
        return $.ajax({
            url: AMF_DATA.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: data,
            timeout: timeout || 35000
        });
    }

    function responseMessage(response, fallback) {
        if (response && response.data) {
            if (typeof response.data.message === 'string') return response.data.message;
            if ($.isArray(response.data) && response.data[0] && response.data[0].message) return response.data[0].message;
        }
        return fallback || '';
    }

    function describeAjaxError(xhr) {
        var message = '';
        if (xhr && xhr.responseJSON && xhr.responseJSON.data) {
            if (typeof xhr.responseJSON.data.message === 'string') {
                message = xhr.responseJSON.data.message;
            } else if ($.isArray(xhr.responseJSON.data) && xhr.responseJSON.data[0] && xhr.responseJSON.data[0].message) {
                message = xhr.responseJSON.data[0].message;
            }
        }
        if (!message && xhr && xhr.statusText && xhr.statusText !== 'error') message = xhr.statusText;
        if (!message) message = AMF_DATA.i18n.error;
        var status = xhr && xhr.status ? 'HTTP ' + xhr.status + ' — ' : '';
        return status + message;
    }

    function scheduleRetry(xhr) {
        var busy = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.code === 'amf_scan_busy';
        if (busy && running) {
            $('#amf-progress-message').text('Le lot précédent est encore verrouillé côté serveur. Nouvelle tentative dans 2 secondes…');
            window.setTimeout(processNext, 2000);
            return true;
        }

        var status = xhr ? parseInt(xhr.status || 0, 10) : 0;
        var transient = status === 0 || status === 408 || status === 429 || status === 500 || status === 502 || status === 503 || status === 504;
        if (!transient || retryCount >= maxRetries || !running) return false;

        retryCount++;
        var wait = Math.min(15000, 1500 * Math.pow(2, retryCount - 1));
        $('#amf-progress-message').text('Erreur temporaire (' + describeAjaxError(xhr) + '). Nouvelle tentative ' + retryCount + '/' + maxRetries + ' dans ' + Math.round(wait / 1000) + ' s…');

        window.setTimeout(function () {
            if (!running) return;
            request('amf_scan_status')
                .done(function (response) { if (response && response.success) render(response.data); })
                .always(function () { if (running) processNext(); });
        }, wait);
        return true;
    }

    function processNext() {
        if (!running || !current || !current.id) return;

        request('amf_process_batch', { scan_id: current.id })
            .done(function (response) {
                if (!response || !response.success) {
                    running = false;
                    $('#amf-progress-message').text('Réponse serveur invalide. Cliquez sur « Reprendre » pour réessayer.');
                    setButtons();
                    return;
                }
                retryCount = 0;
                render(response.data);
                if (response.data.status === 'complete') {
                    running = false;
                    setButtons();
                    window.setTimeout(function () { window.location.reload(); }, 700);
                    return;
                }
                var delay = Math.max(0, parseInt((performance && performance.request_delay_ms) || response.data.request_delay_ms || 0, 10));
                window.setTimeout(processNext, delay);
            })
            .fail(function (xhr) {
                if (scheduleRetry(xhr)) return;
                running = false;
                var message = describeAjaxError(xhr);
                if (xhr && xhr.status === 403) message += ' Rechargez la page pour renouveler la session/nonce, puis cliquez sur « Reprendre ».';
                else message += ' Le scan est enregistré : cliquez sur « Reprendre » pour continuer au dernier lot validé.';
                $('#amf-progress-message').text(message);
                setButtons();
            });
    }

    $('#amf-start-scan').on('click', function () {
        if (current && current.exists && !window.confirm(AMF_DATA.i18n.confirmRestart)) return;
        request('amf_start_scan')
            .done(function (response) {
                if (response && response.success) {
                    retryCount = 0;
                    running = true;
                    render(response.data);
                    processNext();
                }
            })
            .fail(function (xhr) { $('#amf-progress-message').text(describeAjaxError(xhr)); });
    });

    $('#amf-resume-scan').on('click', function () {
        var scanId = parseInt($('#amf-progress').attr('data-scan-id') || (current && current.id) || 0, 10);
        if (!scanId) return;
        request('amf_resume_scan', { scan_id: scanId })
            .done(function (response) {
                if (response && response.success) {
                    retryCount = 0;
                    running = true;
                    render(response.data);
                    processNext();
                }
            })
            .fail(function (xhr) { $('#amf-progress-message').text(describeAjaxError(xhr)); });
    });

    $('#amf-pause-scan').on('click', function () {
        running = false;
        $('#amf-progress-message').text('Pause locale : la progression est enregistrée. Cliquez sur « Reprendre » pour continuer.');
        setButtons();
    });

    function readPerformanceForm() {
        var values = {};
        $('.amf-performance-input').each(function () { values[$(this).data('key')] = parseInt($(this).val() || 0, 10); });
        return values;
    }

    function fillPerformanceForm(values) {
        $.each(values || {}, function (key, value) { $('.amf-performance-input[data-key="' + key + '"]').val(value); });
    }

    $('.amf-preset').on('click', function () {
        var name = $(this).data('preset');
        if (AMF_DATA.presets && AMF_DATA.presets[name]) {
            fillPerformanceForm(AMF_DATA.presets[name]);
            $('#amf-settings-message').text('Préréglage chargé. Cliquez sur « Enregistrer les réglages » pour l’appliquer.');
        }
    });

    $('#amf-save-performance').on('click', function () {
        var button = $(this);
        button.prop('disabled', true);
        $('#amf-settings-message').text('Enregistrement…');
        request('amf_save_performance_settings', { settings: readPerformanceForm() })
            .done(function (response) {
                if (response && response.success) {
                    performance = $.extend({}, response.data.settings || {});
                    AMF_DATA.performance = performance;
                    fillPerformanceForm(performance);
                    if (response.data.status) render(response.data.status);
                    $('#amf-settings-message').text(response.data.message || 'Réglages enregistrés.');
                } else $('#amf-settings-message').text('Impossible d’enregistrer les réglages.');
            })
            .fail(function (xhr) { $('#amf-settings-message').text(describeAjaxError(xhr)); })
            .always(function () { button.prop('disabled', false); });
    });

    $('#amf-save-deletion').on('click', function () {
        var button = $(this);
        var enabled = $('#amf-deletion-enabled').is(':checked') ? 1 : 0;
        button.prop('disabled', true);
        $('#amf-deletion-message').text('Enregistrement…');
        request('amf_save_deletion_settings', { enabled: enabled })
            .done(function (response) {
                if (response && response.success) {
                    AMF_DATA.deletionEnabled = !!response.data.enabled;
                    $('#amf-deletion-message').text(response.data.message + ' Rechargez les listes pour actualiser les boutons.');
                } else {
                    $('#amf-deletion-message').text('Impossible d’enregistrer ce réglage.');
                }
            })
            .fail(function (xhr) { $('#amf-deletion-message').text(describeAjaxError(xhr)); })
            .always(function () { button.prop('disabled', false); });
    });

    $(document).on('click', '.amf-family-toggle', function () {
        var button = $(this);
        var summary = button.closest('tr');
        var detailsRow = summary.next('.amf-family-details-row');
        var container = detailsRow.find('.amf-family-details');
        var opening = detailsRow.prop('hidden');

        detailsRow.prop('hidden', !opening);
        button.attr('aria-expanded', opening ? 'true' : 'false').text(opening ? '▼' : '▶');
        if (!opening || container.data('loaded') === 1) return;

        request('amf_family_details', {
            scan_id: container.data('scan-id'),
            family_key: container.data('family-key') || '',
            attachment_id: container.data('attachment-id') || 0,
            file_id: container.data('file-id') || 0
        })
            .done(function (response) {
                if (response && response.success) {
                    container.html(response.data.html).data('loaded', 1);
                } else {
                    container.text('Impossible de charger le détail de cette famille.');
                }
            })
            .fail(function (xhr) { container.text(describeAjaxError(xhr)); });
    });

    $(document).on('click', '.amf-delete-media', function () {
        if (!window.confirm(AMF_DATA.i18n.confirmDeleteMedia)) return;
        var button = $(this);
        button.prop('disabled', true).text('Suppression…');
        request('amf_delete_media', {
            scan_id: button.data('scan-id'),
            attachment_id: button.data('attachment-id')
        }, 60000)
            .done(function (response) {
                if (response && response.success) {
                    window.alert(response.data.message || 'Média supprimé.');
                    window.location.reload();
                } else {
                    window.alert(responseMessage(response, 'Suppression impossible.'));
                    button.prop('disabled', false).text('Supprimer');
                }
            })
            .fail(function (xhr) {
                window.alert(describeAjaxError(xhr));
                button.prop('disabled', false).text('Supprimer');
            });
    });

    function familyRisk(node) {
        var n = $(node);
        return {
            attached: parseInt(n.attr('data-attachment-id') || '0', 10) > 0,
            refs: parseInt(n.attr('data-direct-refs') || '0', 10),
            ambiguous: parseInt(n.attr('data-ambiguous') || '0', 10),
            uses: parseInt(n.attr('data-usage-count') || '0', 10)
        };
    }

    function askFamilyDeletionConfirmation(families, files, risk) {
        var warning = 'SUPPRESSION DÉFINITIVE DE ' + families + ' FAMILLE(S) (' + files + ' fichier(s)).\n';
        if (risk.attached) warning += '\nATTENTION : ' + risk.attached + ' média(s) seront aussi supprimés de la médiathèque WordPress.';
        if (risk.uses) warning += '\n' + risk.uses + ' référence(s) au moins sont recensées pour ces médias. Des images du site peuvent disparaître.';
        if (risk.refs) warning += '\n' + risk.refs + ' référence(s) par URL directe sont recensées.';
        if (risk.ambiguous) warning += '\n' + risk.ambiguous + ' fichier(s) ont une association ambiguë.';
        warning += '\n\nSauvegarde complète conseillée. Cette opération est irréversible. Continuer ?';
        if (!window.confirm(warning)) return null;

        var needsOverride = !!(risk.attached || risk.uses || risk.refs || risk.ambiguous);
        if (!needsOverride) return { force: 0, confirmation: '' };

        var typed = window.prompt('CONFIRMATION RENFORCÉE : tapez exactement SUPPRIMER pour autoriser la suppression, y compris les fichiers et médias encore référencés.');
        if (typed !== 'SUPPRIMER') {
            window.alert('Suppression annulée : confirmation incorrecte.');
            return null;
        }
        return { force: 1, confirmation: 'SUPPRIMER' };
    }

    $(document).on('click', '.amf-refresh-woo-media', function () {
        var button = $(this);
        button.prop('disabled', true).text('Vérification…');
        request('amf_refresh_woo_image_usage', {
            scan_id: button.data('scan-id'),
            attachment_id: button.data('attachment-id')
        }, 60000)
            .done(function (response) {
                if (response && response.success) {
                    window.alert(response.data.message || 'Références WooCommerce actualisées.');
                    window.location.reload();
                } else window.alert(responseMessage(response, 'Actualisation impossible.'));
            })
            .fail(function (xhr) { window.alert(describeAjaxError(xhr)); })
            .always(function () { button.prop('disabled', false).text('Revérifier les images principales / galeries WooCommerce'); });
    });

    $(document).on('click', '.amf-delete-family', function () {
        if (familyBulkBusy) return;
        var button = $(this);
        var flags = familyRisk(button);
        var risk = {
            attached: flags.attached ? 1 : 0,
            refs: flags.refs,
            uses: flags.uses,
            ambiguous: flags.ambiguous
        };
        var confirmation = askFamilyDeletionConfirmation(1, parseInt(button.data('file-count') || 0, 10), risk);
        if (!confirmation) return;

        button.prop('disabled', true).text('Suppression…');
        request('amf_delete_family', {
            scan_id: button.data('scan-id'),
            family_key: button.data('family-key'),
            force: confirmation.force,
            confirmation: confirmation.confirmation
        }, 60000)
            .done(function (response) {
                if (response && response.success) {
                    window.alert(response.data.message || 'Famille supprimée.');
                    window.location.reload();
                } else {
                    window.alert(responseMessage(response, 'Suppression impossible.'));
                    button.prop('disabled', false).text('Supprimer la famille');
                }
            })
            .fail(function (xhr) {
                window.alert(describeAjaxError(xhr));
                button.prop('disabled', false).text('Supprimer la famille');
            });
    });

    function selectedFamilyCheckboxes() {
        return $('.amf-family-checkbox:enabled:checked');
    }

    function refreshFamilyBulkControls() {
        var enabled = $('.amf-family-checkbox:enabled');
        var selected = selectedFamilyCheckboxes();
        var all = $('.amf-family-select-all');
        var count = selected.length;
        var action = $('.amf-family-bulk-action').first().val();

        all.prop('checked', enabled.length > 0 && count === enabled.length);
        all.prop('indeterminate', count > 0 && count < enabled.length);
        $('.amf-bulk-selection-count').text(count + (count > 1 ? ' familles sélectionnées' : ' famille sélectionnée'));
        $('.amf-apply-family-bulk').prop('disabled', familyBulkBusy || !count || !action);
    }

    $(document).on('change', '.amf-family-select-all', function () {
        $('.amf-family-checkbox:enabled').prop('checked', $(this).is(':checked'));
        refreshFamilyBulkControls();
    });

    $(document).on('change', '.amf-family-checkbox', refreshFamilyBulkControls);
    $(document).on('change', '.amf-family-bulk-action', function () {
        if (familyBulkBusy) return;
        $('.amf-family-bulk-action').val($(this).val());
        refreshFamilyBulkControls();
    });

    $(document).on('click', '.amf-apply-family-bulk', function () {
        if (familyBulkBusy) return;
        var action = $(this).closest('.amf-bulk-actions').find('.amf-family-bulk-action').val();
        var selected = selectedFamilyCheckboxes();
        if (action !== 'delete' || !selected.length) return;
        if (productRunning || productRequestInFlight) {
            window.alert('Terminez ou mettez en pause la vérification des produits avant de supprimer.');
            return;
        }

        var families = [];
        var totalFiles = 0;
        selected.each(function () {
            var checkbox = $(this);
            var key = String(checkbox.data('family-key') || checkbox.val() || '');
            if (!key) return;
            families.push({
                key: key,
                files: parseInt(checkbox.data('file-count') || 0, 10),
                risk: familyRisk(checkbox)
            });
            totalFiles += parseInt(checkbox.data('file-count') || 0, 10);
        });
        if (!families.length) return;

        var risks = { attached: 0, refs: 0, uses: 0, ambiguous: 0 };
        families.forEach(function (item) {
            risks.attached += item.risk.attached ? 1 : 0;
            risks.refs += item.risk.refs;
            risks.uses += item.risk.uses;
            risks.ambiguous += item.risk.ambiguous;
        });
        var confirmation = askFamilyDeletionConfirmation(families.length, totalFiles, risks);
        if (!confirmation) return;

        familyBulkBusy = true;
        var button = $('.amf-apply-family-bulk');
        var select = $('.amf-family-bulk-action');
        var checkboxes = $('.amf-family-checkbox, .amf-family-select-all');
        var progress = $('.amf-bulk-progress');
        var scanId = parseInt($('.amf-bulk-actions').data('scan-id') || (current && current.id) || 0, 10);
        var success = 0;
        var failures = [];
        var index = 0;

        button.prop('disabled', true).text('Suppression…');
        select.prop('disabled', true);
        checkboxes.prop('disabled', true);
        $('.amf-delete-family, .amf-delete-media, .amf-refresh-product-index').prop('disabled', true);

        function finishBulkDelete() {
            progress.text('Terminé : ' + success + ' famille(s) supprimée(s)' + (failures.length ? ', ' + failures.length + ' échec(s).' : '.'));
            var message = success + ' famille(s) supprimée(s) avec leurs sous-fichiers.';
            if (failures.length) {
                message += '\n\n' + failures.length + ' famille(s) n’ont pas pu être supprimées :\n- ' + failures.join('\n- ');
            }
            window.alert(message);
            window.location.reload();
        }

        function deleteNextFamily() {
            if (index >= families.length) {
                finishBulkDelete();
                return;
            }
            var item = families[index];
            progress.text('Suppression ' + (index + 1) + ' / ' + families.length + '…');
            request('amf_delete_family', {
                scan_id: scanId,
                family_key: item.key,
                force: confirmation.force,
                confirmation: confirmation.confirmation
            }, 60000)
                .done(function (response) {
                    if (response && response.success) {
                        success++;
                    } else {
                        failures.push(responseMessage(response, 'Famille ' + item.key + ' : suppression impossible.'));
                    }
                })
                .fail(function (xhr) {
                    failures.push('Famille ' + item.key + ' : ' + describeAjaxError(xhr));
                })
                .always(function () {
                    index++;
                    window.setTimeout(deleteNextFamily, 100);
                });
        }

        deleteNextFamily();
    });

    // v2.3: native product image assignments have a separate, small resumable job.
    var productRunning = false;
    var productState = AMF_DATA.productIndex || {};
    var productRequestInFlight = false;
    var productRetry = 0;
    var productTimer = null;

    function productMessage(message) { $('.amf-product-index-message').text(message || ''); }
    function productControls() {
        $('.amf-refresh-product-index').prop('disabled', familyBulkBusy || productRunning || productRequestInFlight);
        $('.amf-pause-product-index').prop('hidden', !productRunning);
    }
    function productStep(restart) {
        if (!productRunning || productRequestInFlight) return;
        productRequestInFlight = true;
        productControls();
        $('.amf-product-index-progress').prop('hidden', false);
        productMessage('Vérification des images produits en cours…');
        request('amf_product_index', { restart: restart ? '1' : '0' }, 45000)
            .done(function (response) {
                if (!response || !response.success) {
                    productRunning = false;
                    productMessage(responseMessage(response, 'Vérification interrompue. Aucun résultat négatif ne sera validé.'));
                    return;
                }
                productState = response.data;
                productRetry = 0;
                var n = parseInt(productState.current || 0, 10);
                var total = parseInt(productState.total || 0, 10);
                $('.amf-product-index-progress progress').val(total ? Math.min(100, n * 100 / total) : 100);
                $('.amf-product-index-progress span').text(n.toLocaleString('fr-FR') + ' / ' + total.toLocaleString('fr-FR') + ' éléments vérifiés');
                if (productState.ready) {
                    productRunning = false;
                    productMessage('Vérification terminée. Actualisation du tableau...');
                    window.setTimeout(function () { window.location.reload(); }, 250);
                } else if (productState.status === 'stale') {
                    productRunning = false;
                    productMessage(productState.message || 'Des produits ont changé pendant la vérification. Relancez-la.');
                }
            })
            .fail(function (xhr) {
                productRetry++;
                if ((xhr.status === 409 || xhr.status === 0 || xhr.status >= 500) && productRetry <= 3 && productRunning) {
                    productMessage('Reprise après interruption (' + productRetry + '/3). ' + describeAjaxError(xhr));
                } else {
                    productRunning = false;
                    productMessage(describeAjaxError(xhr) + ' Cliquez sur Actualiser pour recommencer cette vérification uniquement.');
                }
            })
            .always(function () {
                productRequestInFlight = false;
                productControls();
                if (productRunning) productTimer = window.setTimeout(function () { productStep(false); }, productRetry ? 2500 * productRetry : 150);
            });
    }
    $(document).on('click', '.amf-refresh-product-index', function () {
        if (familyBulkBusy || productRequestInFlight) return;
        if ($('.amf-family-checkbox:checked').length && !window.confirm('La vérification actualisera la page et annulera la sélection courante. Continuer ?')) return;
        productRunning = true; productRetry = 0;
        productStep(!productState.working);
    });
    $(document).on('click', '.amf-pause-product-index', function () {
        productRunning = false;
        window.clearTimeout(productTimer);
        productControls();
        productMessage('Pause demandée. Le lot déjà parti se terminera ; la progression sera conservée.');
    });
    if ($('.amf-product-index[data-auto="1"]').length && !productState.ready && !$('.amf-family-checkbox:checked').length) {
        productRunning = true;
        productTimer = window.setTimeout(function () { productStep(false); }, 350);
    }

    $(document).on('click', '.amf-family-open', function () {
        var row = $(this).closest('.amf-family-summary');
        var toggle = row.find('.amf-family-toggle').first();
        if (toggle.attr('aria-expanded') !== 'true') toggle.trigger('click');
        else row.next('.amf-family-details-row')[0].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    });

    function loadFamilyFiles(box, page) {
        if (box.data('loading')) return;
        box.data('loading', true).attr('aria-busy', 'true');
        request('amf_family_files', {
            scan_id: box.data('scan-id'), family_key: box.data('family-key') || '',
            attachment_id: box.data('attachment-id') || 0, file_id: box.data('file-id') || 0,
            file_page: page || 1
        }, 45000).done(function (response) {
            if (response && response.success) box.html(response.data.html).data('loaded', 1);
            else box.text(responseMessage(response, 'Chargement des fichiers impossible. Repliez puis rouvrez ce bloc pour réessayer.'));
        }).fail(function (xhr) {
            box.text(describeAjaxError(xhr) + ' Repliez puis rouvrez ce bloc pour réessayer.');
        }).always(function () { box.data('loading', false).attr('aria-busy', 'false'); });
    }
    // Native <details> remains keyboard accessible; only its contents are lazy loaded.
    $(document).on('click', '.amf-file-accordion > summary', function () {
        var details = $(this).parent();
        var box = details.children('.amf-files-lazy');
        if (!details.prop('open') && !box.data('loaded')) loadFamilyFiles(box, 1);
    });
    $(document).on('click', '.amf-file-page', function () {
        var box = $(this).closest('.amf-files-lazy');
        loadFamilyFiles(box, parseInt($(this).attr('data-file-page'), 10));
    });
    document.addEventListener('error', function (event) {
        var img = event.target;
        if (img && img.tagName === 'IMG' && img.classList.contains('amf-thumb')) {
            var placeholder = document.createElement('span');
            placeholder.className = 'amf-thumb amf-thumb-placeholder';
            placeholder.textContent = 'Aperçu indisponible';
            img.replaceWith(placeholder);
        }
    }, true);

    refreshFamilyBulkControls();
    render(current);
    if (AMF_DATA.autoRestart && $('#amf-start-scan').length) {
        // Consume the nonce-bearing URL before confirmation or any retry.
        if (window.history && window.history.replaceState && AMF_DATA.dashboardUrl) {
            window.history.replaceState(null, '', AMF_DATA.dashboardUrl);
        }
        $('#amf-start-scan').trigger('click');
    }
    if (current && current.exists && current.status !== 'complete') $('#amf-progress-message').text(defaultMessage(current));
})(jQuery);
