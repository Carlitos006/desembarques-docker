(function ($, window) {
    'use strict';

    if (!$ || !window) {
        return;
    }

    var appConfig = window.AppConfig || {};
    var translations = appConfig.translations || {};
    var csrfToken = appConfig.csrfToken || '';
    var bootstrap = window.bootstrap || null;

    function translate(key, fallback, replacements) {
        var value;

        if (Object.prototype.hasOwnProperty.call(translations, key)) {
            value = translations[key];
        } else {
            value = typeof fallback === 'string' ? fallback : '';
        }

        if (replacements && typeof replacements === 'object') {
            Object.keys(replacements).forEach(function (placeholder) {
                if (!Object.prototype.hasOwnProperty.call(replacements, placeholder)) {
                    return;
                }

                var pattern = new RegExp('\\{\\{\\s*' + placeholder + '\\s*\\}\\}', 'g');
                value = String(value).replace(pattern, replacements[placeholder]);
            });
        }

        return value;
    }

    function escapeHtml(value) {
        if (value === null || value === undefined) {
            return '';
        }

        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function normalizeValue(value) {
        if (value === null || value === undefined) {
            return '';
        }

        return String(value);
    }

    function normalizeText(value) {
        if (value === null || value === undefined) {
            return '';
        }

        return String(value)
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/\s+/g, ' ')
            .trim();
    }

    $(function () {
        var modalElement = document.getElementById('pedimentosModal');
        if (!modalElement) {
            return;
        }

        var $modal = $(modalElement);
        var parseUrl = $modal.attr('data-parse-url') || '';
        if (!parseUrl) {
            return;
        }

        var modalInstance = null;
        if (bootstrap && typeof bootstrap.Modal !== 'undefined') {
            modalInstance = bootstrap.Modal.getOrCreateInstance(modalElement, {});
        }

        var $form = $modal.find('[data-pedimentos-form]');
        var $fileInput = $modal.find('[data-pedimentos-file]');
        var $processButton = $modal.find('[data-pedimentos-process]');
        var $feedback = $modal.find('[data-pedimentos-feedback]');
        var $secList = $modal.find('[data-pedimentos-sec-list]');
        var $secSearch = $modal.find('[data-pedimentos-sec-search]');
        var $selectAll = $modal.find('[data-pedimentos-select-all]');
        var $selectNone = $modal.find('[data-pedimentos-select-none]');
        var $selectInvert = $modal.find('[data-pedimentos-select-invert]');
        var $selectedSummary = $modal.find('[data-pedimentos-selected-summary]');
        var $secCount = $modal.find('[data-pedimentos-sec-count]');
        var $resultsCount = $modal.find('[data-pedimentos-results-count]');
        var $tableBody = $modal.find('[data-pedimentos-table-body]');
        var $confirmButton = $modal.find('[data-pedimentos-confirm]');
        var $serverFilterButton = $modal.find('[data-pedimentos-filter-server]');
        var $exportButton = $modal.find('[data-pedimentos-export]');
        var $debugOutput = $modal.find('[data-pedimentos-debug]');
        var $headerBox = $modal.find('[data-pedimentos-header]');
        var $headerNum = $modal.find('[data-pedimentos-header-num]');
        var $headerCve = $modal.find('[data-pedimentos-header-cve]');
        var $headerRazon = $modal.find('[data-pedimentos-header-razon]');
        var $headerEntrada = $modal.find('[data-pedimentos-header-fecha-entrada]');
        var $headerPago = $modal.find('[data-pedimentos-header-fecha-pago]');
        var $fieldsGroup = $('#pedimentos-fields');
        var $headerJsonInput = $('#pedimentos-header-json');
        var $itemsJsonInput = $('#pedimentos-items-json');
        var $pedimentoField = $('#pedimento');

        var hasForm = $form.length > 0;

        if (!$fileInput.length || !$processButton.length || !$secList.length || !$tableBody.length || !$confirmButton.length || !$fieldsGroup.length) {
            return;
        }

        var filterButtonDefaultText = $serverFilterButton.length ? $serverFilterButton.text() : translate('pedimentos.modal.filter_server_button', 'Apply server filter');
        var filteringText = translate('pedimentos.modal.filtering', 'Filtering…');
        var headerPlaceholder = translate('pedimentos.modal.header.placeholder', '—');
        var debugEmptyText = $debugOutput.length && $debugOutput.text().trim() !== ''
            ? $debugOutput.text()
            : translate('pedimentos.modal.debug_empty', 'No diagnostic information available.');

        if (!hasForm && $processButton.attr('type') === 'submit') {
            $processButton.attr('type', 'button');
        }

        var secMap = new Map();
        var secEntries = [];
        var selectedSecs = new Set();
        var allItems = [];
        var currentSearch = '';
        var initialTableEmptyHtml = $tableBody.html();
        var isRequestInProgress = false;
        var lastRequestMode = null;
        var headerData = null;

        function getTextDriverMetadata(payload) {
            if (!payload || typeof payload !== 'object') {
                return { driver: '', details: {} };
            }

            var driver = '';
            if (typeof payload.text_driver === 'string' && payload.text_driver.trim() !== '') {
                driver = payload.text_driver.trim();
            } else if (payload.debug && typeof payload.debug.text_driver === 'string' && payload.debug.text_driver.trim() !== '') {
                driver = payload.debug.text_driver.trim();
            }

            var detailsSource = payload.text_driver_details && typeof payload.text_driver_details === 'object'
                ? payload.text_driver_details
                : payload.debug && typeof payload.debug.text_driver_details === 'object'
                    ? payload.debug.text_driver_details
                    : {};

            var details = detailsSource && typeof detailsSource === 'object' ? detailsSource : {};

            return { driver: driver, details: details };
        }

        function extractExecutableLabel(value) {
            if (typeof value !== 'string') {
                return '';
            }

            var candidate = value.trim();
            if (!candidate) {
                return '';
            }

            var quoted = candidate.match(/^(["'])(.+?)\1/);
            if (quoted) {
                candidate = quoted[2];
            } else {
                var spaceIndex = candidate.search(/\s/);
                if (spaceIndex !== -1) {
                    candidate = candidate.slice(0, spaceIndex);
                }
            }

            candidate = candidate.replace(/^['"]|['"]$/g, '');
            var parts = candidate.split(/[\\\/]/).filter(Boolean);
            if (parts.length) {
                candidate = parts[parts.length - 1];
            }

            return candidate.trim();
        }

        function resolveDriverDetailLabel(details) {
            if (!details || typeof details !== 'object') {
                return '';
            }

            if (typeof details.display === 'string' && details.display.trim() !== '') {
                return details.display.trim();
            }

            var sources = [];
            if (typeof details.command === 'string' && details.command.trim() !== '') {
                sources.push(details.command);
            }
            if (typeof details.binary === 'string' && details.binary.trim() !== '') {
                sources.push(details.binary);
            }

            for (var i = 0; i < sources.length; i++) {
                var label = extractExecutableLabel(sources[i]);
                if (label) {
                    return label;
                }
            }

            return '';
        }

        function formatTextDriverLabel(driver, details) {
            if (!driver) {
                return '';
            }

            var raw = String(driver).trim();
            if (!raw) {
                return '';
            }

            var label = raw.toLowerCase() === 'smalot' ? 'smalot/pdfparser' : raw;
            var detailLabel = resolveDriverDetailLabel(details);

            if (detailLabel) {
                label += ' (' + detailLabel + ')';
            }

            return label;
        }

        function buildDriverMessage(payload) {
            var metadata = getTextDriverMetadata(payload);
            var label = formatTextDriverLabel(metadata.driver, metadata.details);

            if (!label) {
                return '';
            }

            return translate('pedimentos.modal.feedback.text_driver', 'Motor de texto: {{driver}}', {
                driver: label
            });
        }

        function showFeedback(type, message) {
            if (!message) {
                $feedback.empty();
                return;
            }

            var alertClass = 'alert-secondary';
            if (type === 'success') {
                alertClass = 'alert-success';
            } else if (type === 'danger') {
                alertClass = 'alert-danger';
            } else if (type === 'warning') {
                alertClass = 'alert-warning';
            } else if (type === 'info') {
                alertClass = 'alert-info';
            }

            var html = '<div class="alert ' + alertClass + ' mb-0" role="alert">' + escapeHtml(message) + '</div>';
            $feedback.html(html);
        }

        function clearFeedback() {
            $feedback.empty();
        }

        function setLoadingState(isLoading, mode) {
            isRequestInProgress = isLoading;
            lastRequestMode = isLoading ? mode : null;

            if (isLoading) {
                $processButton.prop('disabled', true);
                if ($serverFilterButton.length) {
                    $serverFilterButton.prop('disabled', true);
                }
                if ($exportButton.length) {
                    $exportButton.prop('disabled', true);
                }

                if (mode === 'filter' && $serverFilterButton.length) {
                    $serverFilterButton.text(filteringText);
                } else {
                    $processButton.text(translate('pedimentos.modal.processing', 'Procesando…'));
                }
            } else {
                $processButton.prop('disabled', false);
                $processButton.text(translate('pedimentos.modal.process_button', 'Procesar PDF'));

                if ($serverFilterButton.length) {
                    $serverFilterButton.text(filterButtonDefaultText);
                    $serverFilterButton.prop('disabled', selectedSecs.size === 0 || allItems.length === 0);
                }

                if ($exportButton.length) {
                    $exportButton.prop('disabled', getFilteredItems().length === 0);
                }
            }
        }

        function formatSecLabel(secNumber) {
            return translate('pedimentos.modal.sec_label', 'SEC {{number}}', { number: secNumber });
        }

        function updateSelectedSummary() {
            var count = selectedSecs.size;
            if (count === 0) {
                $selectedSummary.text(translate('pedimentos.modal.selected_summary_none', 'Seleccionados: 0'));
                return;
            }

            var sorted = Array.from(selectedSecs).sort(function (a, b) { return a - b; });
            var sample = sorted.slice(0, 5).map(function (sec) {
                return formatSecLabel(sec);
            }).join(', ');

            if (sorted.length > 5) {
                sample += ' …';
            }

            $selectedSummary.text(translate('pedimentos.modal.selected_summary_some', 'Seleccionados: {{count}} — Ej: {{sample}}', {
                count: count,
                sample: sample
            }));
        }

        function sanitizeHeaderValue(value) {
            if (value === null || value === undefined) {
                return '';
            }

            return String(value).trim();
        }

        function updateHeaderBox(data) {
            headerData = null;

            if (!$headerBox.length) {
                return;
            }

            var placeholder = headerPlaceholder || '—';
            var sanitized = {
                num_pedimento: '',
                cve_pedimento: '',
                razon_social: '',
                fecha_entrada: '',
                fecha_pago: ''
            };

            if (data && typeof data === 'object') {
                if (Object.prototype.hasOwnProperty.call(data, 'num_pedimento')) {
                    sanitized.num_pedimento = sanitizeHeaderValue(data.num_pedimento);
                }
                if (Object.prototype.hasOwnProperty.call(data, 'cve_pedimento')) {
                    sanitized.cve_pedimento = sanitizeHeaderValue(data.cve_pedimento);
                }
                if (Object.prototype.hasOwnProperty.call(data, 'razon_social')) {
                    sanitized.razon_social = sanitizeHeaderValue(data.razon_social);
                }
                if (Object.prototype.hasOwnProperty.call(data, 'fecha_entrada')) {
                    sanitized.fecha_entrada = sanitizeHeaderValue(data.fecha_entrada);
                }
                if (Object.prototype.hasOwnProperty.call(data, 'fecha_pago')) {
                    sanitized.fecha_pago = sanitizeHeaderValue(data.fecha_pago);
                }
            }

            if ($headerNum.length) {
                $headerNum.text(sanitized.num_pedimento || placeholder);
            }
            if ($headerCve.length) {
                $headerCve.text(sanitized.cve_pedimento || placeholder);
            }
            if ($headerRazon.length) {
                $headerRazon.text(sanitized.razon_social || placeholder);
            }
            if ($headerEntrada.length) {
                $headerEntrada.text(sanitized.fecha_entrada || placeholder);
            }
            if ($headerPago.length) {
                $headerPago.text(sanitized.fecha_pago || placeholder);
            }

            var hasData = Object.keys(sanitized).some(function (key) {
                return sanitized[key] !== '';
            });

            if (hasData) {
                headerData = sanitized;
                $headerBox.removeClass('d-none');
            } else {
                $headerBox.addClass('d-none');
            }
        }

        function clearSubmissionPayload() {
            if ($headerJsonInput.length) {
                $headerJsonInput.val('');
            }
            if ($itemsJsonInput.length) {
                $itemsJsonInput.val('');
            }
        }

        function getFilteredItems() {
            if (selectedSecs.size === 0) {
                return [];
            }

            return allItems.filter(function (item) {
                var secValue = Number(item && item.sec);
                if (Number.isNaN(secValue)) {
                    return false;
                }

                return selectedSecs.has(secValue);
            });
        }

        function renderTable() {
            var items = getFilteredItems();
            if (items.length === 0) {
                $tableBody.html(initialTableEmptyHtml);
            } else {
                var rows = items.map(function (item) {
                    var fractionParts = [];
                    var fraccion8 = normalizeValue(item && item.fraccion8);
                    var fraccion2 = normalizeValue(item && item.fraccion2);
                    if (fraccion8 !== '') {
                        fractionParts.push(fraccion8);
                    }
                    if (fraccion2 !== '') {
                        fractionParts.push(fraccion2);
                    }
                    var fraction = fractionParts.join(' ');

                    var modelOrUmc = normalizeValue(item && item.modelo_cols);
                    if (modelOrUmc === '') {
                        modelOrUmc = normalizeValue(item && (item.umc_inicial || item.umc_unidad));
                    }

                    return '<tr>'
                        + '<td>' + escapeHtml(normalizeValue(item && item.sec)) + '</td>'
                        + '<td>' + escapeHtml(normalizeValue(item && item.descripcion)) + '</td>'
                        + '<td>' + escapeHtml(fraction) + '</td>'
                        + '<td>' + escapeHtml(modelOrUmc) + '</td>'
                        + '<td>' + escapeHtml(normalizeValue(item && item.umc_cant)) + '</td>'
                        + '</tr>';
                }).join('');

                $tableBody.html(rows);
            }

            var countText = translate('pedimentos.modal.results_count', '{{count}} partidas', {
                count: items.length
            });
            $resultsCount.text(countText);

            if ($exportButton.length) {
                $exportButton.prop('disabled', items.length === 0);
            }
        }

        function buildSubmissionItems(items) {
            return items.map(function (item) {
                var fractionParts = [];
                var fraccion8 = normalizeValue(item && item.fraccion8);
                var fraccion2 = normalizeValue(item && item.fraccion2);
                if (fraccion8 !== '') {
                    fractionParts.push(fraccion8);
                }
                if (fraccion2 !== '') {
                    fractionParts.push(fraccion2);
                }
                var fraction = fractionParts.join(' ');

                var modelOrUmc = normalizeValue(item && item.modelo_cols);
                if (modelOrUmc === '') {
                    modelOrUmc = normalizeValue(item && (item.umc_inicial || item.umc_unidad));
                }

                return {
                    sec: normalizeValue(item && item.sec),
                    descripcion: normalizeValue(item && item.descripcion),
                    fraccion: fraction,
                    modelo: modelOrUmc,
                    umc_cant: normalizeValue(item && item.umc_cant)
                };
            });
        }

        function setDebugInfo(debugInfo) {
            if (!$debugOutput.length) {
                return;
            }

            if (debugInfo === null || debugInfo === undefined) {
                $debugOutput.text(debugEmptyText);
                return;
            }

            if (typeof debugInfo === 'string') {
                $debugOutput.text(debugInfo);
                return;
            }

            try {
                $debugOutput.text(JSON.stringify(debugInfo, null, 2));
            } catch (error) {
                $debugOutput.text(debugEmptyText);
            }
        }

        function refreshSecList() {
            $secCount.text(String(secEntries.length));
            buildSecList(getFilteredSecEntries());
        }

        function getFilteredSecEntries() {
            if (!currentSearch) {
                return secEntries;
            }

            return secEntries.filter(function (entry) {
                var label = formatSecLabel(entry.number);
                var description = entry.description ? String(entry.description) : '';
                var normalizedLabel = normalizeText(label + ' ' + entry.number);
                var normalizedDescription = normalizeText(description);
                return normalizedLabel.indexOf(currentSearch) !== -1 || normalizedDescription.indexOf(currentSearch) !== -1;
            });
        }

        function buildSecList(secs) {
            $secList.empty();

            if (!Array.isArray(secs) || secs.length === 0) {
                $secList.append('<p class="text-muted small mb-0">' + escapeHtml(translate('pedimentos.modal.secs_empty', 'No se detectaron SECs.')) + '</p>');
                return;
            }

            var fragment = document.createDocumentFragment();

            secs.forEach(function (entry) {
                var secNumber = entry.number;
                var labelText = formatSecLabel(secNumber);
                var description = entry.description ? String(entry.description) : '';
                if (description !== '') {
                    labelText += ' — ' + description;
                }

                var label = document.createElement('label');
                label.className = 'd-flex align-items-center gap-2 mb-1 small';

                var input = document.createElement('input');
                input.type = 'checkbox';
                input.className = 'form-check-input';
                input.value = String(secNumber);
                input.checked = selectedSecs.has(secNumber);

                input.addEventListener('change', function () {
                    var numericValue = Number(this.value);
                    if (Number.isNaN(numericValue)) {
                        return;
                    }

                    if (this.checked) {
                        selectedSecs.add(numericValue);
                    } else {
                        selectedSecs.delete(numericValue);
                    }

                    updateSelectedSummary();
                    renderTable();
                    updateConfirmButton();
                });

                var span = document.createElement('span');
                span.textContent = labelText;

                label.appendChild(input);
                label.appendChild(span);
                fragment.appendChild(label);
            });

            $secList.append(fragment);
        }

        function updateConfirmButton() {
            var count = selectedSecs.size;
            var hasItems = allItems.length > 0;
            var buttonText = translate('pedimentos.modal.confirm_button_empty', 'Agregar pedimentos');

            if (count === 1) {
                buttonText = translate('pedimentos.modal.confirm_button_single', 'Agregar 1 pedimento');
            } else if (count > 1) {
                buttonText = translate('pedimentos.modal.confirm_button', 'Agregar {{count}} pedimentos', { count: count });
            }

            $confirmButton.text(buttonText);
            $confirmButton.prop('disabled', count === 0 || !hasItems);

            if ($serverFilterButton.length) {
                var canFilterServer = count > 0 && hasItems && !isRequestInProgress;
                $serverFilterButton.prop('disabled', !canFilterServer);
                if (!isRequestInProgress) {
                    $serverFilterButton.text(filterButtonDefaultText);
                }
            }
        }

        function resetState() {
            secMap.clear();
            secEntries = [];
            selectedSecs.clear();
            allItems = [];
            currentSearch = '';
            isRequestInProgress = false;
            lastRequestMode = null;
            clearSubmissionPayload();
            updateHeaderBox(null);
            $secSearch.val('');
            $secCount.text('0');
            $secList.empty();
            $selectedSummary.text(translate('pedimentos.modal.selected_summary_none', 'Seleccionados: 0'));
            $tableBody.html(initialTableEmptyHtml);
            $resultsCount.text(translate('pedimentos.modal.results_count', '{{count}} partidas', { count: 0 }));
            if ($serverFilterButton.length) {
                $serverFilterButton.prop('disabled', true);
                $serverFilterButton.text(filterButtonDefaultText);
            }
            if ($exportButton.length) {
                $exportButton.prop('disabled', true);
            }
            setDebugInfo(null);
            updateConfirmButton();
            refreshSecList();
        }

        function handleSuccess(json, options) {
            var opts = options && typeof options === 'object' ? options : {};
            var desiredSelection = Array.isArray(opts.desiredSelection)
                ? opts.desiredSelection.map(function (value) {
                    return Number(value);
                }).filter(function (value) {
                    return !Number.isNaN(value);
                })
                : null;

            allItems = Array.isArray(json.items) ? json.items : [];
            secMap.clear();
            selectedSecs.clear();

            allItems.forEach(function (item) {
                var secNumber = Number(item && item.sec);
                if (Number.isNaN(secNumber)) {
                    return;
                }

                if (!secMap.has(secNumber)) {
                    var description = item && item.descripcion ? String(item.descripcion) : '';
                    secMap.set(secNumber, { number: secNumber, description: description });
                }
            });

            secEntries = Array.from(secMap.values()).sort(function (a, b) {
                return a.number - b.number;
            });

            var nextSelection = new Set();
            if (desiredSelection && desiredSelection.length > 0) {
                var desiredSet = new Set(desiredSelection);
                secEntries.forEach(function (entry) {
                    if (desiredSet.has(entry.number)) {
                        nextSelection.add(entry.number);
                    }
                });
            } else {
                secEntries.forEach(function (entry) {
                    nextSelection.add(entry.number);
                });
            }

            if (nextSelection.size === 0 && secEntries.length > 0) {
                secEntries.forEach(function (entry) {
                    nextSelection.add(entry.number);
                });
            }

            selectedSecs = nextSelection;

            currentSearch = '';
            $secSearch.val('');
            refreshSecList();
            updateSelectedSummary();
            renderTable();
            updateHeaderBox(json && json.header ? json.header : null);
            updateConfirmButton();
            setDebugInfo(json && json.debug ? json.debug : null);

            var message = translate('pedimentos.modal.results_count', '{{count}} partidas', {
                count: allItems.length
            });
            var driverMessage = buildDriverMessage(json);
            if (driverMessage) {
                message += ' — ' + driverMessage;
            }
            showFeedback('success', message);
        }

        function requestPdf(options) {
            var opts = options && typeof options === 'object' ? options : {};

            if (isRequestInProgress) {
                return Promise.resolve(null);
            }

            var mode = opts.mode === 'filter' ? 'filter' : 'process';
            var shouldReset = opts.resetState !== false;
            var secFilter = Array.isArray(opts.secFilter)
                ? opts.secFilter.map(function (value) {
                    return Number(value);
                }).filter(function (value) {
                    return !Number.isNaN(value) && value > 0;
                })
                : [];
            var desiredSelection = Array.isArray(opts.desiredSelection) ? opts.desiredSelection : secFilter;

            var inputElement = $fileInput.length ? $fileInput[0] : null;
            var file = inputElement && inputElement.files && inputElement.files.length ? inputElement.files[0] : null;

            if (!file) {
                showFeedback('warning', translate('pedimentos.modal.feedback.no_file', 'Selecciona un archivo PDF antes de procesar.'));
                return Promise.resolve(null);
            }

            if (mode === 'filter' && secFilter.length === 0) {
                showFeedback('warning', translate('pedimentos.modal.confirm_no_selection', 'Selecciona al menos un SEC para continuar.'));
                return Promise.resolve(null);
            }

            if (shouldReset) {
                resetState();
            }

            clearFeedback();
            setLoadingState(true, mode);

            var formData = new FormData();
            formData.append('pdf', file);
            if (csrfToken) {
                formData.append('csrf_token', csrfToken);
            }
            if (secFilter.length > 0) {
                formData.append('sec_filter', secFilter.join(','));
            }

            return window.fetch(parseUrl, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json'
                }
            })
                .then(function (response) {
                    return response.text().then(function (text) {
                        return { response: response, text: text };
                    });
                })
                .then(function (payload) {
                    var json;

                    try {
                        json = JSON.parse(payload.text);
                    } catch (error) {
                        throw { type: 'parse', raw: payload.text };
                    }

                    if (!payload.response.ok || !json || json.ok !== true) {
                        var message = json && json.error
                            ? String(json.error)
                            : translate('pedimentos.modal.feedback.server_error', 'No fue posible procesar el PDF en el servidor.');
                        showFeedback('danger', message);
                        if (json && json.debug) {
                            setDebugInfo(json.debug);
                        }
                        if (shouldReset) {
                            allItems = [];
                            secMap.clear();
                            secEntries = [];
                            selectedSecs = new Set();
                            refreshSecList();
                            updateSelectedSummary();
                            renderTable();
                            updateHeaderBox(null);
                            clearSubmissionPayload();
                        }
                        return null;
                    }

                    handleSuccess(json, { desiredSelection: desiredSelection });
                    return json;
                })
                .catch(function (error) {
                    if (error && error.type === 'parse') {
                        showFeedback('danger', translate('pedimentos.modal.feedback.parse_error', 'El servidor devolvió una respuesta no válida.'));
                        setDebugInfo(error.raw || null);
                    } else {
                        showFeedback('danger', translate('pedimentos.modal.feedback.network_error', 'Error de red al procesar el PDF.'));
                        if (error && error.message) {
                            // eslint-disable-next-line no-console
                            console.error('Pedimentos modal request error:', error);
                        }
                    }
                    if (shouldReset) {
                        updateHeaderBox(null);
                        clearSubmissionPayload();
                    }
                    return null;
                })
                .finally(function () {
                    setLoadingState(false, mode);
                    updateConfirmButton();
                });
        }

        function processPdf(event) {
            if (event && typeof event.preventDefault === 'function') {
                event.preventDefault();
            }

            if (event && typeof event.stopPropagation === 'function') {
                event.stopPropagation();
            }

            if (event && typeof event.stopImmediatePropagation === 'function') {
                event.stopImmediatePropagation();
            }

            if ($processButton.prop('disabled') || isRequestInProgress) {
                return;
            }

            requestPdf({ mode: 'process', resetState: true });
        }

        if (hasForm) {
            $form.on('submit', processPdf);
        }
        $processButton.on('click', processPdf);

        if ($serverFilterButton.length) {
            $serverFilterButton.on('click', function () {
                var secs = Array.from(selectedSecs);
                requestPdf({
                    mode: 'filter',
                    resetState: false,
                    secFilter: secs,
                    desiredSelection: secs
                });
            });
        }

        if ($exportButton.length) {
            $exportButton.on('click', function () {
                var items = getFilteredItems();
                if (!items.length) {
                    return;
                }

                var exportItems = items.map(function (item) {
                    return {
                        sec: normalizeValue(item && item.sec),
                        descripcion: normalizeValue(item && item.descripcion),
                        fraccion8: normalizeValue(item && item.fraccion8),
                        fraccion2: normalizeValue(item && item.fraccion2),
                        umc_inicial: normalizeValue(item && (item.umc_inicial || item.umc_unidad)),
                        umc_cant: normalizeValue(item && item.umc_cant),
                        umc_unidad: normalizeValue(item && item.umc_unidad),
                        umt_pu: normalizeValue(item && item.umt_pu),
                        umt_unidad: normalizeValue(item && item.umt_unidad),
                        pais: normalizeValue(item && item.pais),
                        marca: normalizeValue(item && item.marca),
                        igi_tasa: normalizeValue(item && item.igi_tasa),
                        iva_tasa: normalizeValue(item && item.iva_tasa),
                        id_1: normalizeValue(item && item.id_1),
                        id_2: normalizeValue(item && item.id_2),
                        valor_ref: normalizeValue(item && item.valor_ref),
                        identif: normalizeValue(item && item.identif),
                        comp1: normalizeValue(item && item.comp1),
                        comp2: normalizeValue(item && item.comp2),
                        comp3: normalizeValue(item && item.comp3)
                    };
                });

                try {
                    var blob = new Blob([JSON.stringify(exportItems, null, 2)], { type: 'application/json' });
                    var url = URL.createObjectURL(blob);
                    var link = document.createElement('a');
                    link.href = url;
                    link.download = 'pedimentos_seleccionados.json';
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                    URL.revokeObjectURL(url);
                } catch (error) {
                    // eslint-disable-next-line no-console
                    console.error('Pedimentos modal export error:', error);
                }
            });
        }

        $selectAll.on('click', function () {
            secEntries.forEach(function (entry) {
                selectedSecs.add(entry.number);
            });
            refreshSecList();
            updateSelectedSummary();
            renderTable();
            updateConfirmButton();
        });

        $selectNone.on('click', function () {
            selectedSecs.clear();
            refreshSecList();
            updateSelectedSummary();
            renderTable();
            updateConfirmButton();
        });

        $selectInvert.on('click', function () {
            var inverted = new Set();
            secEntries.forEach(function (entry) {
                if (!selectedSecs.has(entry.number)) {
                    inverted.add(entry.number);
                }
            });
            selectedSecs = inverted;
            refreshSecList();
            updateSelectedSummary();
            renderTable();
            updateConfirmButton();
        });

        $secSearch.on('input', function () {
            currentSearch = normalizeText($secSearch.val());
            refreshSecList();
        });

        $confirmButton.on('click', function () {
            var count = selectedSecs.size;
            if (count === 0) {
                showFeedback('warning', translate('pedimentos.modal.confirm_no_selection', 'Selecciona al menos un SEC para continuar.'));
                return;
            }

            var fieldsApi = $fieldsGroup.data('dynamicFieldApi');
            if (!fieldsApi || typeof fieldsApi.setValues !== 'function') {
                showFeedback('danger', translate('pedimentos.modal.confirm_error', 'No fue posible actualizar los pedimentos en el formulario.'));
                return;
            }

            clearSubmissionPayload();

            var values = Array.from(selectedSecs).sort(function (a, b) { return a - b; }).map(function (secNumber) {
                var entry = secMap.get(secNumber);
                var description = entry && entry.description ? String(entry.description) : '';
                var label = formatSecLabel(secNumber);
                return description !== '' ? label + ' — ' + description : label;
            });

            fieldsApi.setValues(values);

            if ($itemsJsonInput.length) {
                var submissionItems = buildSubmissionItems(getFilteredItems());
                if (submissionItems.length) {
                    try {
                        $itemsJsonInput.val(JSON.stringify(submissionItems));
                    } catch (error) {
                        $itemsJsonInput.val('');
                    }
                } else {
                    $itemsJsonInput.val('');
                }
            }

            if ($headerJsonInput.length) {
                if (headerData) {
                    try {
                        $headerJsonInput.val(JSON.stringify(headerData));
                    } catch (error) {
                        $headerJsonInput.val('');
                    }
                } else {
                    $headerJsonInput.val('');
                }
            }

            if ($pedimentoField.length && headerData && headerData.num_pedimento) {
                var currentValue = String($pedimentoField.val() || '').trim();
                if (currentValue === '') {
                    $pedimentoField.val(headerData.num_pedimento);
                }
            }
            if (modalInstance && typeof modalInstance.hide === 'function') {
                modalInstance.hide();
            }
        });

        modalElement.addEventListener('show.bs.modal', function () {
            clearFeedback();
            $secSearch.val('');
            currentSearch = '';
            refreshSecList();
            updateSelectedSummary();
            updateConfirmButton();
        });

        resetState();
    });
})(window.jQuery, window);
