(function ($, window, document) {
    'use strict';

    if (!$ || !window || !document) {
        return;
    }

    var appConfig = window.AppConfig || {};
    var translations = appConfig.translations || {};
    var currentLanguage = appConfig.language || 'es';

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

    function normalizeValue(value) {
        if (value === null || value === undefined) {
            return '';
        }

        return String(value).trim();
    }

    function sanitizeHeader(data) {
        var allowedKeys = ['num_pedimento', 'cve_pedimento', 'razon_social', 'fecha_entrada', 'fecha_pago'];
        var sanitized = {};

        if (!data || typeof data !== 'object') {
            return sanitized;
        }

        allowedKeys.forEach(function (key) {
            if (!Object.prototype.hasOwnProperty.call(data, key)) {
                return;
            }
            var value = normalizeValue(data[key]);
            if (value !== '') {
                sanitized[key] = value;
            }
        });

        return sanitized;
    }

    function sanitizeItems(items) {
        if (!Array.isArray(items)) {
            return [];
        }

        return items.map(function (item) {
            return {
                sec: normalizeValue(item && item.sec),
                descripcion: normalizeValue(item && item.descripcion),
                fraccion: normalizeValue(item && item.fraccion),
                modelo: normalizeValue(item && item.modelo),
                umc_cant: normalizeValue(item && item.umc_cant)
            };
        }).filter(function (item) {
            return item.sec !== '' || item.descripcion !== '' || item.fraccion !== '' || item.modelo !== '' || item.umc_cant !== '';
        });
    }

    function buildPedimentosFromItems(items) {
        var labels = new Map();

        items.forEach(function (item) {
            var sec = normalizeValue(item && item.sec);
            if (sec === '') {
                return;
            }
            if (!labels.has(sec)) {
                var description = normalizeValue(item && item.descripcion);
                var label = translate('pedimentos.modal.sec_label', 'SEC {{number}}', { number: sec });
                if (description !== '') {
                    label += ' — ' + description;
                }
                labels.set(sec, label);
            }
        });

        return Array.from(labels.values());
    }

    function sanitizePedimentos(pedimentos, items) {
        var list = Array.isArray(pedimentos) ? pedimentos : [];
        var sanitized = list.map(function (value) {
            return normalizeValue(value);
        }).filter(function (value) {
            return value !== '';
        });

        if (sanitized.length === 0 && Array.isArray(items) && items.length > 0) {
            sanitized = buildPedimentosFromItems(items);
        }

        return sanitized;
    }

    function setJsonValue($input, value) {
        if (!$input || !$input.length) {
            return;
        }

        var finalValue = '';

        if (Array.isArray(value) && value.length > 0) {
            try {
                finalValue = JSON.stringify(value);
            } catch (error) {
                finalValue = '';
            }
        } else if (value && typeof value === 'object' && Object.keys(value).length > 0) {
            try {
                finalValue = JSON.stringify(value);
            } catch (error) {
                finalValue = '';
            }
        }

        $input.val(finalValue);
    }

    function showFeedback($feedback, message, type) {
        if (!$feedback || !$feedback.length) {
            return;
        }

        var classes = ['text-muted', 'text-success', 'text-danger', 'text-info'];
        $feedback.removeClass(classes.join(' '));

        if (!message) {
            $feedback.addClass('text-muted');
            $feedback.text('');
            return;
        }

        var className = 'text-info';
        if (type === 'success') {
            className = 'text-success';
        } else if (type === 'error') {
            className = 'text-danger';
        }

        $feedback.addClass(className);
        $feedback.text(message);
    }

    function isAllowedOrigin(currentOrigin, eventOrigin) {
        if (!eventOrigin || eventOrigin === 'null') {
            return currentOrigin === 'null' || currentOrigin === '';
        }

        if (currentOrigin === 'null' || currentOrigin === '') {
            return true;
        }

        return eventOrigin === currentOrigin;
    }

    $(function () {
        var $fieldsGroup = $('#pedimentos-fields');
        var $headerInput = $('#pedimentos-header-json');
        var $itemsInput = $('#pedimentos-items-json');
        var $packagesInput = $('#pedimentos-packages-json');
        var $feedback = $('#pedimentos-bridge-feedback');
        var $pedimentoField = $('#pedimento');
        var $openButton = $('[data-pedimentos-open-table]');
        var $headerSummary = $('[data-pedimentos-header-summary]');
        var $headerClear = $('[data-pedimentos-header-clear]');
        var $headerEmpty = $headerSummary.find('[data-pedimentos-header-empty]');
        var $headerList = $headerSummary.find('[data-pedimentos-header-list]');
        var currentOrigin = window.location.origin || '';
        var tableWindow = null;
        var headerPlaceholder = normalizeValue($headerSummary.length ? $headerSummary.data('placeholder') : '');
        if (headerPlaceholder === '') {
            headerPlaceholder = '—';
        }

        var emptyText = normalizeValue($headerSummary.length ? $headerSummary.data('emptyText') : '');
        if (emptyText === '') {
            emptyText = translate('pedimentos.bridge.header.empty', currentLanguage === 'en'
                ? 'No headers have been imported yet.'
                : 'Aún no hay cabeceras importadas.');
        }

        var packages = [];
        var manualExtras = new Map();
        var isApplyingFields = false;

        function canonicalPedimento(value) {
            var normalized = normalizeValue(value);
            if (normalized === '') {
                return '';
            }

            return normalized.toLowerCase();
        }

        function generatePackageId() {
            return 'pkg-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10);
        }

        function computePackageSet(excludeId) {
            var set = new Set();

            packages.forEach(function (pkg) {
                if (!pkg || (excludeId && pkg.id === excludeId)) {
                    return;
                }

                if (!Array.isArray(pkg.pedimentos)) {
                    return;
                }

                pkg.pedimentos.forEach(function (value) {
                    var key = canonicalPedimento(value);
                    if (key !== '') {
                        set.add(key);
                    }
                });
            });

            return set;
        }

        function sanitizePackageEntry(entry) {
            var header = sanitizeHeader(entry && entry.header ? entry.header : {});
            var items = sanitizeItems(entry && entry.items ? entry.items : []);
            var pedimentos = sanitizePedimentos(entry && entry.pedimentos ? entry.pedimentos : [], items);
            var hasHeader = header && Object.keys(header).length > 0;
            var hasItems = Array.isArray(items) && items.length > 0;
            var hasPedimentos = Array.isArray(pedimentos) && pedimentos.length > 0;

            if (!hasHeader && !hasItems && !hasPedimentos) {
                return null;
            }

            return {
                id: entry && typeof entry.id === 'string' && entry.id.trim() !== '' ? entry.id : generatePackageId(),
                header: header,
                items: items,
                pedimentos: pedimentos
            };
        }

        function parsePackagesInput() {
            if (!$packagesInput.length) {
                return [];
            }

            var rawValue = normalizeValue($packagesInput.val());
            if (rawValue === '') {
                return [];
            }

            try {
                var parsed = JSON.parse(rawValue);
                if (!Array.isArray(parsed)) {
                    return [];
                }

                return parsed.map(sanitizePackageEntry).filter(function (entry) {
                    return !!entry;
                });
            } catch (error) {
                return [];
            }
        }

        function parseLegacyHeaders() {
            if (!$headerInput.length) {
                return [];
            }

            var rawValue = normalizeValue($headerInput.val());
            if (rawValue === '') {
                return [];
            }

            try {
                var parsed = JSON.parse(rawValue);
                if (Array.isArray(parsed)) {
                    return parsed.map(function (value) {
                        return sanitizeHeader(value);
                    }).filter(function (header) {
                        return header && Object.keys(header).length > 0;
                    });
                }

                if (parsed && typeof parsed === 'object') {
                    var header = sanitizeHeader(parsed);
                    return header && Object.keys(header).length > 0 ? [header] : [];
                }
            } catch (error) {
                return [];
            }

            return [];
        }

        function parseLegacyItems() {
            if (!$itemsInput.length) {
                return [];
            }

            var rawValue = normalizeValue($itemsInput.val());
            if (rawValue === '') {
                return [];
            }

            try {
                var parsed = JSON.parse(rawValue);
                if (!Array.isArray(parsed)) {
                    return [];
                }

                if (parsed.length > 0 && Array.isArray(parsed[0])) {
                    return parsed.map(function (items) {
                        return sanitizeItems(items);
                    });
                }

                return [sanitizeItems(parsed)];
            } catch (error) {
                return [];
            }
        }

        function collectCurrentFieldValues() {
            var values = [];

            if (!$fieldsGroup.length) {
                return values;
            }

            $fieldsGroup.find('input').each(function () {
                var value = normalizeValue($(this).val());
                if (value !== '') {
                    values.push(value);
                }
            });

            return values;
        }

        function loadPackagesFromInputs() {
            var parsed = parsePackagesInput();

            if (parsed.length === 0) {
                var legacyHeaders = parseLegacyHeaders();
                var legacyItems = parseLegacyItems();

                if (legacyHeaders.length > 0 || legacyItems.length > 0) {
                    var header = legacyHeaders.length > 0 ? legacyHeaders[0] : {};
                    var items = legacyItems.length > 0 ? legacyItems[0] : [];
                    var pedimentos = sanitizePedimentos(collectCurrentFieldValues(), items);
                    var legacyEntry = sanitizePackageEntry({
                        header: header,
                        items: items,
                        pedimentos: pedimentos
                    });

                    if (legacyEntry) {
                        parsed.push(legacyEntry);
                    }
                }
            }

            packages = parsed;
        }

        function persistState() {
            if ($packagesInput.length) {
                setJsonValue($packagesInput, packages);
            }

            if ($headerInput.length) {
                var headers = packages.map(function (pkg) {
                    return pkg && pkg.header ? pkg.header : {};
                });
                setJsonValue($headerInput, headers);
            }

            if ($itemsInput.length) {
                var itemsCollection = packages.map(function (pkg) {
                    return pkg && Array.isArray(pkg.items) ? pkg.items : [];
                });
                setJsonValue($itemsInput, itemsCollection);
            }
        }

        function appendDetail($container, labelKey, value) {
            if (!$container || !$container.length) {
                return;
            }

            var normalized = normalizeValue(value);
            if (normalized === '') {
                return;
            }

            var label = translate(labelKey, labelKey);
            var $dt = $('<dt class="col-6 text-muted"></dt>').text(label);
            var $dd = $('<dd class="col-6 fw-semibold mb-0"></dd>').text(normalized);
            $container.append($dt, $dd);
        }

        function renderHeaderSummary() {
            if (!$headerSummary.length) {
                return;
            }

            if (!Array.isArray(packages) || packages.length === 0) {
                if ($headerList.length) {
                    $headerList.empty();
                }
                if ($headerEmpty.length) {
                    $headerEmpty.text(emptyText).removeClass('d-none');
                }
                $headerSummary.addClass('d-none').attr('aria-hidden', 'true');
                return;
            }

            var removeLabel = translate('pedimentos.bridge.header.remove', currentLanguage === 'en' ? 'Remove' : 'Eliminar');
            var pedimentosLabel = translate('pedimentos.bridge.header.pedimentos_label', currentLanguage === 'en' ? 'Linked pedimentos' : 'Pedimentos vinculados');
            var hasDisplayable = false;
            var fragment = document.createDocumentFragment();

            packages.forEach(function (pkg) {
                if (!pkg) {
                    return;
                }

                var header = pkg.header || {};
                var pedimentos = Array.isArray(pkg.pedimentos) ? pkg.pedimentos : [];
                var hasHeaderData = Object.keys(header).some(function (key) {
                    return header && Object.prototype.hasOwnProperty.call(header, key) && normalizeValue(header[key]) !== '';
                });
                var hasPedimentos = pedimentos.length > 0;

                if (!hasHeaderData && !hasPedimentos) {
                    return;
                }

                hasDisplayable = true;

                var $entry = $('<div class="border rounded p-3 bg-light-subtle" data-pedimentos-header-entry></div>');
                $entry.attr('data-package-id', pkg.id);

                var $top = $('<div class="d-flex justify-content-between align-items-start"></div>');
                var $info = $('<div></div>');
                var primaryValue = header.num_pedimento ? normalizeValue(header.num_pedimento) : '';

                if (primaryValue === '') {
                    primaryValue = header.cve_pedimento ? normalizeValue(header.cve_pedimento) : '';
                }

                if (primaryValue === '') {
                    primaryValue = headerPlaceholder;
                }

                $info.append($('<span class="fw-semibold d-block"></span>').text(primaryValue));
                if (header.razon_social && normalizeValue(header.razon_social) !== '') {
                    $info.append($('<span class="text-muted small d-block"></span>').text(header.razon_social));
                }
                $top.append($info);

                if (removeLabel !== '') {
                    var $remove = $('<button type="button" class="btn btn-link btn-sm p-0" data-pedimentos-header-remove></button>').text(removeLabel);
                    $top.append($remove);
                }

                $entry.append($top);

                var $details = $('<dl class="row small mb-0 mt-3"></dl>');
                appendDetail($details, 'pedimentos.modal.header.num', header.num_pedimento);
                appendDetail($details, 'pedimentos.modal.header.cve', header.cve_pedimento);
                appendDetail($details, 'pedimentos.modal.header.razon', header.razon_social);
                appendDetail($details, 'pedimentos.modal.header.fecha_entrada', header.fecha_entrada);
                appendDetail($details, 'pedimentos.modal.header.fecha_pago', header.fecha_pago);
                if ($details.children().length > 0) {
                    $entry.append($details);
                }

                if (pedimentos.length > 0) {
                    var $pedimentosBlock = $('<div class="small mt-3"></div>');
                    $pedimentosBlock.append($('<span class="text-muted d-block mb-1"></span>').text(pedimentosLabel + ':'));

                    var $pedimentosList = $('<ul class="list-unstyled mb-0 ps-3"></ul>');
                    pedimentos.forEach(function (value) {
                        var normalizedValue = normalizeValue(value);
                        if (normalizedValue === '') {
                            return;
                        }

                        $pedimentosList.append($('<li class="mb-1"></li>').text(normalizedValue));
                    });

                    if ($pedimentosList.children().length > 0) {
                        $pedimentosBlock.append($pedimentosList);
                        $entry.append($pedimentosBlock);
                    }
                }

                fragment.appendChild($entry[0]);
            });

            if (!hasDisplayable) {
                if ($headerList.length) {
                    $headerList.empty();
                }
                if ($headerEmpty.length) {
                    $headerEmpty.text(emptyText).removeClass('d-none');
                }
                $headerSummary.addClass('d-none').attr('aria-hidden', 'true');
                return;
            }

            if ($headerList.length) {
                $headerList.empty()[0].appendChild(fragment);
            }

            if ($headerEmpty.length) {
                $headerEmpty.addClass('d-none').text(emptyText);
            }

            $headerSummary.removeClass('d-none').attr('aria-hidden', 'false');
        }

        function getTotalPackagePedimentosCount() {
            return computePackageSet().size;
        }

        function refreshFields() {
            if (!$fieldsGroup.length) {
                return;
            }

            var fieldsApi = $fieldsGroup.data('dynamicFieldApi');
            if (!fieldsApi || typeof fieldsApi.setValues !== 'function') {
                return;
            }

            var combined = [];
            var added = new Set();

            manualExtras.forEach(function (value, key) {
                if (key === '' || added.has(key)) {
                    return;
                }
                combined.push(value);
                added.add(key);
            });

            packages.forEach(function (pkg) {
                if (!pkg || !Array.isArray(pkg.pedimentos)) {
                    return;
                }

                pkg.pedimentos.forEach(function (value) {
                    var key = canonicalPedimento(value);
                    if (key === '' || added.has(key)) {
                        return;
                    }
                    combined.push(value);
                    added.add(key);
                });
            });

            if (combined.length === 0) {
                combined.push('');
            }

            isApplyingFields = true;
            fieldsApi.setValues(combined);
            isApplyingFields = false;
        }

        function syncManualExtrasFromInputs() {
            if (isApplyingFields || !$fieldsGroup.length) {
                return;
            }

            var extras = new Map();
            var packageSet = computePackageSet();

            $fieldsGroup.find('input').each(function () {
                var value = normalizeValue($(this).val());
                if (value === '') {
                    return;
                }

                var key = canonicalPedimento(value);
                if (key === '' || packageSet.has(key)) {
                    return;
                }

                if (!extras.has(key)) {
                    extras.set(key, value);
                }
            });

            manualExtras = extras;
        }

        function removePackageById(packageId) {
            if (!packageId) {
                return;
            }

            var removed = null;
            packages = packages.filter(function (pkg) {
                if (pkg && pkg.id === packageId) {
                    removed = pkg;
                    return false;
                }

                return true;
            });

            if (!removed) {
                return;
            }

            if (removed.pedimentos && Array.isArray(removed.pedimentos)) {
                removed.pedimentos.forEach(function (value) {
                    var key = canonicalPedimento(value);
                    if (key !== '' && manualExtras.has(key)) {
                        manualExtras.delete(key);
                    }
                });
            }

            persistState();
            renderHeaderSummary();
            refreshFields();
            window.setTimeout(syncManualExtrasFromInputs, 0);

            showFeedback($feedback, translate('pedimentos.bridge.removed', currentLanguage === 'en'
                ? 'The imported header was removed.'
                : 'Se eliminó la cabecera importada.'), 'info');
        }

        function clearAllPackages() {
            if (!Array.isArray(packages) || packages.length === 0) {
                return;
            }

            packages = [];
            persistState();
            renderHeaderSummary();
            refreshFields();
            window.setTimeout(syncManualExtrasFromInputs, 0);

            showFeedback($feedback, translate('pedimentos.bridge.cleared', currentLanguage === 'en'
                ? 'All imported headers were removed.'
                : 'Se eliminaron todas las cabeceras importadas.'), 'info');
        }

        function applyPayload(payload) {
            var items = sanitizeItems(payload && payload.items ? payload.items : []);
            var pedimentos = sanitizePedimentos(payload && payload.pedimentos ? payload.pedimentos : [], items);
            var header = sanitizeHeader(payload && payload.header ? payload.header : {});
            var fieldsApi = $fieldsGroup.data('dynamicFieldApi');

            if (!fieldsApi || typeof fieldsApi.setValues !== 'function') {
                showFeedback($feedback, translate('pedimentos.bridge.error', currentLanguage === 'en'
                    ? 'Unable to update the pedimentos with the received data.'
                    : 'No fue posible actualizar los pedimentos con los datos recibidos.'), 'error');
                return;
            }

            syncManualExtrasFromInputs();

            var entry = sanitizePackageEntry({
                header: header,
                items: items,
                pedimentos: pedimentos
            });

            if (!entry) {
                showFeedback($feedback, translate('pedimentos.bridge.empty', currentLanguage === 'en'
                    ? 'No pedimentos were received from the external tool.'
                    : 'No se recibieron pedimentos desde la herramienta externa.'), 'info');
                return;
            }

            packages.push(entry);
            persistState();
            renderHeaderSummary();
            refreshFields();
            window.setTimeout(syncManualExtrasFromInputs, 0);

            if ($pedimentoField && $pedimentoField.length && entry.header && entry.header.num_pedimento) {
                var currentValue = normalizeValue($pedimentoField.val());
                if (currentValue === '') {
                    $pedimentoField.val(entry.header.num_pedimento);
                }
            }

            if (entry.pedimentos.length > 0) {
                showFeedback($feedback, translate('pedimentos.bridge.success', currentLanguage === 'en'
                    ? 'Imported {{count}} pedimentos from the external tool (total: {{total}}).'
                    : 'Se importaron {{count}} pedimentos desde la herramienta externa (total acumulado: {{total}}).', {
                    count: entry.pedimentos.length,
                    total: getTotalPackagePedimentosCount()
                }), 'success');
            } else {
                showFeedback($feedback, translate('pedimentos.bridge.header_only', currentLanguage === 'en'
                    ? 'The header was stored, but no pedimentos were detected.'
                    : 'Se guardó la cabecera, pero no se detectaron pedimentos.'), 'info');
            }

            window.setTimeout(function () {
                var $firstInput = $fieldsGroup.find('input').first();
                if ($firstInput.length) {
                    $firstInput.trigger('focus');
                }
            }, 0);
        }

        function handleMessage(event) {
            if (!event || typeof event !== 'object') {
                return;
            }

            if (!isAllowedOrigin(currentOrigin, event.origin)) {
                return;
            }

            var data = event.data;
            if (!data || typeof data !== 'object' || data.type !== 'pedimentos-extractor') {
                return;
            }

            applyPayload(data.payload || {});

            if (tableWindow && !tableWindow.closed) {
                tableWindow.focus();
            }
        }

        window.addEventListener('message', handleMessage, false);

        function reloadFromInputs(payload) {
            var options = payload && typeof payload === 'object' ? payload : {};

            if ($headerInput.length && Object.prototype.hasOwnProperty.call(options, 'header')) {
                $headerInput.val(options.header === undefined || options.header === null ? '' : String(options.header));
            }

            if ($itemsInput.length && Object.prototype.hasOwnProperty.call(options, 'items')) {
                $itemsInput.val(options.items === undefined || options.items === null ? '' : String(options.items));
            }

            if ($packagesInput.length && Object.prototype.hasOwnProperty.call(options, 'packages')) {
                $packagesInput.val(options.packages === undefined || options.packages === null ? '' : String(options.packages));
            }

            manualExtras = new Map();
            packages = [];

            loadPackagesFromInputs();
            persistState();
            renderHeaderSummary();

            window.setTimeout(function () {
                syncManualExtrasFromInputs();
                refreshFields();
            }, 0);

            showFeedback($feedback, '');
        }

        $(document).on('pedimentos:load-from-inputs', function (event, payload) {
            reloadFromInputs(payload);
        });

        if ($headerClear.length) {
            $headerClear.on('click', function (event) {
                event.preventDefault();
                clearAllPackages();
            });
        }

        if ($headerSummary.length) {
            $headerSummary.on('click', '[data-pedimentos-header-remove]', function (event) {
                event.preventDefault();
                var packageId = $(this).closest('[data-pedimentos-header-entry]').attr('data-package-id') || '';
                removePackageById(packageId);
            });
        }

        if ($fieldsGroup.length) {
            $fieldsGroup.on('input change', 'input', function () {
                syncManualExtrasFromInputs();
            });

            $fieldsGroup.on('click', '.dynamic-field-remove', function () {
                window.setTimeout(syncManualExtrasFromInputs, 0);
            });
        }

        $openButton.on('click', function (event) {
            event.preventDefault();

            var url = $(this).attr('data-pedimentos-table-url') || '';
            if (!url) {
                showFeedback($feedback, translate('pedimentos.bridge.error', currentLanguage === 'en'
                    ? 'Unable to update the pedimentos with the received data.'
                    : 'No fue posible actualizar los pedimentos con los datos recibidos.'), 'error');
                return;
            }

            if (tableWindow && !tableWindow.closed) {
                tableWindow.focus();
                showFeedback($feedback, translate('pedimentos.bridge.already_open', currentLanguage === 'en'
                    ? 'The external tool is already open in another window.'
                    : 'La herramienta externa ya está abierta en otra ventana.'), 'info');
                return;
            }

            var popupWidth = 1200;
            var popupHeight = 800;
            var screenWidth = window.screen && window.screen.width ? window.screen.width : popupWidth;
            var screenHeight = window.screen && window.screen.height ? window.screen.height : popupHeight;
            var width = Math.min(popupWidth, screenWidth);
            var height = Math.min(popupHeight, screenHeight);
            var left = 0;
            var top = 0;

            if (typeof window.screenLeft === 'number' && typeof window.screenTop === 'number') {
                left = Math.max(0, Math.round(window.screenLeft + (screenWidth - width) / 2));
                top = Math.max(0, Math.round(window.screenTop + (screenHeight - height) / 2));
            } else if (typeof window.screenX === 'number' && typeof window.screenY === 'number') {
                left = Math.max(0, Math.round(window.screenX + (screenWidth - width) / 2));
                top = Math.max(0, Math.round(window.screenY + (screenHeight - height) / 2));
            }

            var windowFeatures = [
                'popup=yes',
                'resizable=yes',
                'scrollbars=yes',
                'toolbar=no',
                'menubar=no',
                'location=no',
                'status=no',
                'width=' + width,
                'height=' + height,
                'left=' + left,
                'top=' + top
            ].join(',');

            tableWindow = window.open(url, 'pedimentosExtractor', windowFeatures);
            if (!tableWindow || tableWindow.closed) {
                tableWindow = null;
                showFeedback($feedback, translate('pedimentos.bridge.window_blocked', currentLanguage === 'en'
                    ? 'The external tool could not be opened. Allow pop-up windows and try again.'
                    : 'No se pudo abrir la herramienta externa. Permite las ventanas emergentes e inténtalo de nuevo.'), 'error');
                return;
            }

            showFeedback($feedback, translate('pedimentos.bridge.opened', currentLanguage === 'en'
                ? 'The external tool opened in a new window. Complete the selection and return here.'
                : 'La herramienta externa se abrió en una nueva ventana. Completa la selección y regresa aquí.'), 'info');
        });

        loadPackagesFromInputs();
        persistState();
        renderHeaderSummary();

        window.setTimeout(function () {
            syncManualExtrasFromInputs();
            refreshFields();
        }, 0);
    });
})(window.jQuery, window, document);
