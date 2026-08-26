(function ($, window) {
    'use strict';

    $(function () {
        var $form = $('#desembarque-form[data-excel-first="true"]');
        if (!$form.length) {
            return;
        }

        var $excel = $('#source_excel');
        var $status = $('#excel-first-status');
        var $meta = $('#excel-first-file-meta');
        var $preview = $('#excel-first-preview');
        var $importersWrap = $('#excel-first-importers-wrap');
        var $importers = $('#excel-first-importers');
        var $payload = $('#aviso-excel-json');
        var $submit = $('#excel-first-submit');
        var $client = $('#cliente_id');
        var $reference = $('#referencia');
        var $cipl = $('#cipl');
        var language = (window.AppConfig && window.AppConfig.language) || 'es';
        var csrfToken = (window.AppConfig && window.AppConfig.csrfToken) || '';
        var parser = window.AvisoPdfGenerator || null;
        var currentState = null;
        var reading = false;

        function tr(es, en) {
            return language === 'en' ? en : es;
        }

        function clean(value) {
            return String(value === null || typeof value === 'undefined' ? '' : value).trim();
        }

        function normalizePedimento(value) {
            return clean(value).toLowerCase().replace(/[^0-9a-z]+/g, '');
        }

        function normalizeClave(value) {
            return clean(value).toUpperCase().replace(/\s+/g, '');
        }

        function groupKey(clave, pedimento) {
            return normalizeClave(clave) + '|' + normalizePedimento(pedimento);
        }

        function setStatus(text, style) {
            $status
                .removeClass('text-bg-secondary text-bg-info text-bg-success text-bg-warning text-bg-danger')
                .addClass(style || 'text-bg-secondary')
                .text(text || '');
        }

        function formatDateTime(value) {
            var text = clean(value);
            if (!text) {
                return '—';
            }

            var match = text.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
            if (!match) {
                return text;
            }

            var output = match[3] + '/' + match[2] + '/' + match[1];
            if (match[4] && match[5] && (match[4] !== '00' || match[5] !== '00')) {
                output += ' · ' + match[4] + ':' + match[5];
            }
            return output;
        }

        function buildGroups(items) {
            var groups = [];
            var map = {};

            (Array.isArray(items) ? items : []).forEach(function (item, index) {
                var key = groupKey(item && item.clave, item && item.num_pedimento);
                if (!map[key]) {
                    map[key] = {
                        key: key,
                        clave: normalizeClave(item && item.clave),
                        num_pedimento: clean(item && item.num_pedimento),
                        importer_name: clean(item && item.importer_name),
                        indexes: []
                    };
                    groups.push(map[key]);
                }
                map[key].indexes.push(index);
                if (!map[key].importer_name && item && item.importer_name) {
                    map[key].importer_name = clean(item.importer_name);
                }
            });

            return groups.filter(function (group) {
                return group.num_pedimento || group.clave;
            });
        }

        function updateItemsFromImporters() {
            if (!currentState || !Array.isArray(currentState.items)) {
                return;
            }

            $importers.find('[data-excel-importer-key]').each(function () {
                var $input = $(this);
                var key = clean($input.attr('data-excel-importer-key'));
                var value = clean($input.val());

                currentState.items.forEach(function (item) {
                    if (groupKey(item.clave, item.num_pedimento) === key) {
                        item.importer_name = value;
                    }
                });
            });
        }

        function setDynamicValues(selector, values) {
            var $group = $(selector);
            var api = $group.data('dynamicFieldApi');
            if (api && typeof api.setValues === 'function') {
                api.setValues(values);
                return;
            }

            $group.empty();
            (Array.isArray(values) ? values : []).forEach(function (value, index) {
                $('<input>')
                    .attr({ type: 'hidden', name: ($group.attr('data-field-name') || 'values') + '[]' })
                    .val(value)
                    .appendTo($group);
            });
        }

        function syncLegacyFields() {
            if (!currentState) {
                return;
            }

            var details = currentState.details || {};
            var items = Array.isArray(currentState.items) ? currentState.items : [];
            var groups = buildGroups(items);
            var manifest = clean(details.manifiesto);
            var uniqueKeys = [];

            groups.forEach(function (group) {
                if (group.clave && uniqueKeys.indexOf(group.clave) === -1) {
                    uniqueKeys.push(group.clave);
                }
            });

            $('#manifiesto').val(manifest);
            $('#folio_aviso').val(manifest ? 'MADE-' + manifest : '');
            $('#pedimento').val(groups.length ? groups[0].num_pedimento : '');
            $('#fecha_desembarque').val(clean(details.fecha_desembarque_eta).slice(0, 10));

            // En el modelo histórico, fecha_embarque representa una etapa posterior del expediente.
            // La fecha de embarque del Excel se conserva en desembarque_aviso_details y no se mezcla aquí.
            $('#fecha_embarque').val('');
            $('#barco').val(clean(details.medio_transporte).slice(0, 150));
            $('#destino').val((clean(details.lugar_desembarque) || clean(details.domicilio_almacenamiento)).slice(0, 150));
            $('#descripcion').val((items.length
                + tr(' mercancías · Pedimentos ', items.length === 1 ? ' merchandise line · Customs entries ' : ' merchandise lines · Customs entries ')
                + (uniqueKeys.join('/') || 'N/A')
                + (manifest ? tr(' · Manifiesto ', ' · Manifest ') + manifest : '')).slice(0, 1000));

            setDynamicValues('#pedimentos-fields', groups.map(function (group) { return group.num_pedimento; }).filter(Boolean));
            setDynamicValues('#manifests-fields', manifest ? [manifest] : []);
            setDynamicValues('#cipls-fields', clean($cipl.val()) ? [clean($cipl.val())] : []);

            var packages = groups.map(function (group) {
                return {
                    header: {
                        num_pedimento: group.num_pedimento,
                        cve_pedimento: group.clave,
                        razon_social: group.importer_name || ''
                    },
                    pedimentos: group.num_pedimento ? [group.num_pedimento] : []
                };
            });
            $('#pedimentos-packages-json').val(JSON.stringify(packages));
            $('#pedimentos-header-json').val(JSON.stringify(packages.map(function (packageItem) { return packageItem.header; })));
        }

        function syncPayload() {
            if (!currentState) {
                $payload.val('');
                updateSubmitState();
                return;
            }

            updateItemsFromImporters();
            syncLegacyFields();

            $payload.val(JSON.stringify({
                details: currentState.details || {},
                items: currentState.items || []
            }));
            updateSubmitState();
        }

        function missingImporters() {
            if (!currentState) {
                return true;
            }
            return buildGroups(currentState.items).some(function (group) {
                return !clean(group.importer_name);
            });
        }

        function updateSubmitState() {
            var ready = Boolean(
                currentState
                && Array.isArray(currentState.items)
                && currentState.items.length
                && clean($client.val())
                && clean($reference.val())
                && !missingImporters()
                && !reading
            );
            $submit.prop('disabled', !ready);
        }

        function renderImporters() {
            $importers.empty();
            if (!currentState) {
                $importersWrap.addClass('d-none');
                return;
            }

            var groups = buildGroups(currentState.items);
            groups.forEach(function (group) {
                var label = (group.clave || tr('Sin clave', 'No key')) + (group.num_pedimento ? ' · ' + group.num_pedimento : '');
                var $column = $('<div>').addClass('col-12 col-lg-6');
                var $label = $('<label>').addClass('form-label small fw-semibold mb-1').text(label);
                var $input = $('<input>')
                    .attr({
                        type: 'text',
                        maxlength: '255',
                        required: 'required',
                        'data-excel-importer-key': group.key,
                        placeholder: tr('Razón social del importador', 'Importer legal business name')
                    })
                    .addClass('form-control')
                    .val(group.importer_name || '');
                $column.append($label, $input);
                $importers.append($column);
            });

            $importersWrap.toggleClass('d-none', groups.length === 0);
        }

        function applyHistoricalImporters(headers) {
            if (!currentState || !parser || typeof parser.matchImporters !== 'function') {
                return;
            }
            currentState.items = parser.matchImporters(currentState.items, Array.isArray(headers) ? headers : []);
        }

        function lookupHistoricalImporters() {
            if (!currentState) {
                return Promise.resolve([]);
            }

            var groups = buildGroups(currentState.items);
            if (!groups.length) {
                return Promise.resolve([]);
            }

            return fetch('../api/desembarques/pedimento_importers.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    csrf_token: csrfToken,
                    pedimentos: groups.map(function (group) {
                        return { clave: group.clave, num_pedimento: group.num_pedimento };
                    })
                })
            }).then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (payload) {
                    if (!response.ok || payload.success === false) {
                        return [];
                    }
                    return Array.isArray(payload.headers) ? payload.headers : [];
                });
            }).catch(function () {
                return [];
            });
        }

        function renderPreview() {
            if (!currentState) {
                $preview.addClass('d-none');
                return;
            }

            var details = currentState.details || {};
            $('[data-excel-preview="manifest"]').text(clean(details.manifiesto) || '—');
            $('[data-excel-preview="transport"]').text(clean(details.medio_transporte) || '—');
            $('[data-excel-preview="imo"]').text(clean(details.imo_transporte) || '—');
            $('[data-excel-preview="items"]').text(String(currentState.items.length));
            $('[data-excel-preview="departure"]').text(formatDateTime(details.fecha_embarque));
            $('[data-excel-preview="landing"]').text(formatDateTime(details.fecha_desembarque_eta));
            $preview.removeClass('d-none');
        }

        function validateParsed(parsed) {
            var details = parsed && parsed.details ? parsed.details : {};
            var items = parsed && Array.isArray(parsed.items) ? parsed.items : [];
            var missing = [];

            if (!clean(details.manifiesto)) { missing.push(tr('manifiesto', 'manifest')); }
            if (!clean(details.medio_transporte)) { missing.push(tr('medio de transporte', 'transport')); }
            if (!clean(details.fecha_desembarque_eta)) { missing.push(tr('fecha de desembarque', 'unloading date')); }
            if (!items.length) { missing.push(tr('mercancías', 'merchandise')); }

            if (missing.length) {
                throw new Error(tr('El Excel no contiene: ', 'The Excel is missing: ') + missing.join(', ') + '.');
            }
        }

        function clearExcelState() {
            currentState = null;
            reading = false;
            $payload.val('');
            $meta.text('');
            $preview.addClass('d-none');
            $importersWrap.addClass('d-none');
            $importers.empty();
            setStatus(tr('Esperando archivo', 'Waiting for file'), 'text-bg-secondary');
            syncLegacyFields();
            updateSubmitState();
        }

        $excel.on('change', function () {
            var file = this.files && this.files.length ? this.files[0] : null;
            if (!file) {
                clearExcelState();
                return;
            }

            if (!parser || typeof parser.parseExcel !== 'function') {
                setStatus(tr('Módulo no disponible', 'Module unavailable'), 'text-bg-danger');
                $meta.text(tr('No fue posible cargar el lector de Excel.', 'The Excel reader could not be loaded.'));
                return;
            }

            reading = true;
            updateSubmitState();
            setStatus(tr('Leyendo Excel…', 'Reading Excel…'), 'text-bg-info');
            $meta.text(file.name);

            parser.parseExcel(file)
                .then(function (parsed) {
                    validateParsed(parsed);
                    currentState = {
                        details: parsed.details || {},
                        items: Array.isArray(parsed.items) ? parsed.items : []
                    };
                    renderPreview();
                    return lookupHistoricalImporters();
                })
                .then(function (headers) {
                    applyHistoricalImporters(headers);
                    renderImporters();
                    syncPayload();
                    setStatus(
                        tr('Excel listo · ', 'Excel ready · ') + currentState.items.length + tr(' mercancías', currentState.items.length === 1 ? ' merchandise line' : ' merchandise lines'),
                        missingImporters() ? 'text-bg-warning' : 'text-bg-success'
                    );
                    if (missingImporters()) {
                        $meta.text(file.name + ' · ' + tr('Completa los importadores faltantes.', 'Complete missing importers.'));
                    } else {
                        $meta.text(file.name + ' · ' + tr('Listo para registrar.', 'Ready to register.'));
                    }
                })
                .catch(function (error) {
                    currentState = null;
                    $payload.val('');
                    $preview.addClass('d-none');
                    $importersWrap.addClass('d-none');
                    $importers.empty();
                    setStatus(tr('Error de Excel', 'Excel error'), 'text-bg-danger');
                    $meta.text(error && error.message ? error.message : tr('No fue posible leer el Excel.', 'Unable to read the Excel file.'));
                })
                .finally(function () {
                    reading = false;
                    updateSubmitState();
                });
        });

        $importers.on('input change', '[data-excel-importer-key]', function () {
            syncPayload();
            if (currentState) {
                setStatus(
                    tr('Excel listo · ', 'Excel ready · ') + currentState.items.length + tr(' mercancías', currentState.items.length === 1 ? ' merchandise line' : ' merchandise lines'),
                    missingImporters() ? 'text-bg-warning' : 'text-bg-success'
                );
            }
        });

        $client.on('change', function () {
            window.setTimeout(updateSubmitState, 150);
        });
        $reference.on('input change', updateSubmitState);
        $(document).ajaxComplete(function (event, xhr, settings) {
            if (settings && String(settings.url || '').indexOf('next_reference.php') !== -1) {
                window.setTimeout(updateSubmitState, 0);
            }
        });
        $cipl.on('input change', function () {
            syncLegacyFields();
        });

        $form.on('reset', function () {
            window.setTimeout(clearExcelState, 0);
        });

        clearExcelState();
    });
}(jQuery, window));
