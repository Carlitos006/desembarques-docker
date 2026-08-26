(function ($) {
    'use strict';

    var config = window.ClientPortalConfig || {};
    var translations = config.translations || {};
    var language = typeof config.language === 'string' && config.language.trim() !== ''
        ? config.language.trim()
        : 'es';
    var clientUserId = null;

    if (typeof config.clientUserId === 'number' && isFinite(config.clientUserId)) {
        clientUserId = Math.trunc(config.clientUserId);
    } else if (typeof config.clientUserId === 'string') {
        var clientUserIdCandidate = config.clientUserId.trim();

        if (/^\d+$/.test(clientUserIdCandidate)) {
            clientUserId = parseInt(clientUserIdCandidate, 10);
        }
    }

    if (! isFinite(clientUserId) || clientUserId <= 0) {
        clientUserId = null;
    }
    var defaultWidgets = ['summary', 'milestones', 'documents', 'messages'];
    var activeWidgets = defaultWidgets.slice();
    var messagesState = [];
    var customizeModalInstance = null;

    function translate(key, fallback, replacements) {
        var value = Object.prototype.hasOwnProperty.call(translations, key)
            ? translations[key]
            : fallback;

        if (typeof value !== 'string') {
            value = fallback || '';
        }

        if (replacements && typeof replacements === 'object') {
            value = value.replace(/\{\{\s*(\w+)\s*\}\}/g, function (match, token) {
                if (Object.prototype.hasOwnProperty.call(replacements, token)) {
                    return String(replacements[token]);
                }

                return match;
            });
        }

        return value;
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function sanitizeWidgetList(list) {
        if (! Array.isArray(list)) {
            return defaultWidgets.slice();
        }

        var normalized = [];

        list.forEach(function (item) {
            if (typeof item !== 'string') {
                return;
            }

            var slug = item.trim();

            if (slug === '' || defaultWidgets.indexOf(slug) === -1) {
                return;
            }

            if (normalized.indexOf(slug) === -1) {
                normalized.push(slug);
            }
        });

        if (normalized.length === 0) {
            return defaultWidgets.slice();
        }

        return normalized;
    }

    function isWidgetActive(widget) {
        return activeWidgets.indexOf(widget) !== -1;
    }

    function buildMessagesUrl() {
        var baseUrl = '../api/client-portal/messages.php';

        if (clientUserId === null) {
            return baseUrl;
        }

        return baseUrl + '?client_user_id=' + encodeURIComponent(String(clientUserId));
    }

    function translateRoleLabel(role, currentLanguage) {
        var normalizedRole = typeof role === 'string' ? role.trim() : '';

        if (normalizedRole === '') {
            return '';
        }

        var languageKey = typeof currentLanguage === 'string' && currentLanguage.indexOf('en') === 0
            ? 'en'
            : 'es';

        var labels = {
            es: {
                admin: 'Administrador',
                usuario: 'Usuario',
                cliente: 'Cliente'
            },
            en: {
                admin: 'Administrator',
                usuario: 'Operator',
                cliente: 'Client'
            }
        };

        if (labels[languageKey] && labels[languageKey][normalizedRole]) {
            return labels[languageKey][normalizedRole];
        }

        return '';
    }

    function applyWidgetVisibility() {
        $('[data-client-portal-widget]').each(function () {
            var $section = $(this);
            var widget = $section.data('clientPortalWidget');

            if (typeof widget !== 'string') {
                return;
            }

            if (isWidgetActive(widget)) {
                $section.removeClass('d-none');
            } else {
                $section.addClass('d-none');
            }
        });
    }

    function updateCustomizeForm() {
        var $form = $('#client-portal-customize-form');

        if (! $form.length) {
            return;
        }

        $form.find('input[name="widgets[]"]').each(function () {
            var $input = $(this);
            var value = $input.val();
            var isChecked = typeof value === 'string' && isWidgetActive(value);

            $input.prop('checked', isChecked);
        });
    }

    function getCustomizeModal() {
        if (customizeModalInstance) {
            return customizeModalInstance;
        }

        var modalElement = document.getElementById('clientPortalCustomizeModal');

        if (modalElement && window.bootstrap && typeof window.bootstrap.Modal === 'function') {
            customizeModalInstance = new window.bootstrap.Modal(modalElement);
        }

        return customizeModalInstance;
    }

    function displayCustomizeFeedback(message, type) {
        var $feedback = $('#client-portal-customize-feedback');

        if (! $feedback.length) {
            return;
        }

        if (message === '') {
            $feedback.addClass('d-none').text('').removeClass('text-danger text-success');
            return;
        }

        $feedback.text(message);
        $feedback.removeClass('text-danger text-success');
        $feedback.addClass(type === 'error' ? 'text-danger' : 'text-success');
        $feedback.removeClass('d-none');
    }

    function displayInlineFeedback($container, message, type) {
        if (! $container || ! $container.length) {
            return;
        }

        $container.find('[data-inline-feedback]').remove();

        if (message === '') {
            return;
        }

        var $message = $('<p></p>')
            .addClass('small mt-2 mb-0')
            .addClass(type === 'error' ? 'text-danger' : 'text-success')
            .attr('data-inline-feedback', 'true')
            .text(message);

        $container.append($message);
    }

    function showMessagesFeedback(message, type) {
        var $container = $('#client-portal-messages-feedback');

        if (! $container.length) {
            return;
        }

        if (message === '') {
            $container.addClass('d-none').text('').removeClass('text-danger text-success');
            return;
        }

        $container.text(message);
        $container.removeClass('text-danger text-success');
        $container.addClass(type === 'error' ? 'text-danger' : 'text-success');
        $container.removeClass('d-none');
    }

    function formatNumber(value) {
        var number = Number(value);

        if (! Number.isFinite(number)) {
            return '0';
        }

        try {
            return new Intl.NumberFormat(language).format(number);
        } catch (error) {
            return String(number);
        }
    }

    function parseDate(value) {
        if (! value) {
            return null;
        }

        var timestamp = Date.parse(value);

        if (Number.isNaN(timestamp)) {
            return null;
        }

        return new Date(timestamp);
    }

    function formatDate(value) {
        var date = parseDate(value);

        if (! date) {
            return '—';
        }

        try {
            return new Intl.DateTimeFormat(language, { dateStyle: 'medium' }).format(date);
        } catch (error) {
            return date.toISOString().split('T')[0];
        }
    }

    function formatDateTime(date) {
        if (! (date instanceof Date)) {
            return '';
        }

        try {
            return new Intl.DateTimeFormat(language, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
        } catch (error) {
            return date.toISOString();
        }
    }

    function formatFileSize(bytes) {
        var size = Number(bytes);

        if (! Number.isFinite(size) || size <= 0) {
            return '0 B';
        }

        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var index = 0;

        while (size >= 1024 && index < units.length - 1) {
            size /= 1024;
            index += 1;
        }

        var decimals = index === 0 || size >= 10 ? 0 : 2;

        return size.toFixed(decimals).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1') + ' ' + units[index];
    }

    function normalizeStatus(record) {
        var slug = record && record.status_slug ? String(record.status_slug).toLowerCase() : '';

        if (slug) {
            return slug;
        }

        if (record && record.status_label) {
            return String(record.status_label).trim().toLowerCase().replace(/\s+/g, '_');
        }

        return '';
    }

    function computeSummary(records) {
        var summary = {
            total: 0,
            pending: 0,
            in_progress: 0,
            completed: 0,
            on_hold: 0
        };

        if (! Array.isArray(records)) {
            return summary;
        }

        records.forEach(function (record) {
            summary.total += 1;
            var status = normalizeStatus(record);

            switch (status) {
                case 'pending':
                    summary.pending += 1;
                    break;
                case 'in_progress':
                    summary.in_progress += 1;
                    break;
                case 'completed':
                    summary.completed += 1;
                    break;
                case 'on_hold':
                    summary.on_hold += 1;
                    break;
                default:
                    break;
            }
        });

        return summary;
    }

    function buildSummaryCard(config) {
        var label = escapeHtml(config.label || '');
        var value = escapeHtml(config.value || '0');
        var accentClass = 'client-portal-summary-card shadow-sm h-100 border-0';

        return [
            '<div class="col">',
            '    <div class="card ' + accentClass + '">',
            '        <div class="card-body">',
            '            <p class="text-muted text-uppercase fw-semibold small mb-2">' + label + '</p>',
            '            <p class="display-6 fw-semibold mb-0">' + value + '</p>',
            '        </div>',
            '    </div>',
            '</div>'
        ].join('');
    }

    function renderSummary(records) {
        var summary = computeSummary(records);
        var $summary = $('#client-portal-summary');

        if (! $summary.length) {
            return;
        }

        var cards = [
            {
                key: 'total',
                label: translate('client_portal.summary.total_shipments', 'Total'),
                value: formatNumber(summary.total)
            },
            {
                key: 'pending',
                label: translate('client_portal.summary.pending', 'Pending'),
                value: formatNumber(summary.pending)
            },
            {
                key: 'in_progress',
                label: translate('client_portal.summary.in_progress', 'In progress'),
                value: formatNumber(summary.in_progress)
            },
            {
                key: 'completed',
                label: translate('client_portal.summary.completed', 'Completed'),
                value: formatNumber(summary.completed)
            },
            {
                key: 'on_hold',
                label: translate('client_portal.summary.on_hold', 'On hold'),
                value: formatNumber(summary.on_hold)
            }
        ];

        var html = cards
            .map(function (card) {
                return buildSummaryCard(card);
            })
            .join('');

        $summary.html(html);

        var $updatedAt = $('[data-portal-updated-at]');
        var timestampText = translate('client_portal.summary.updated_at', 'Updated {{timestamp}}', {
            timestamp: formatDateTime(new Date())
        });

        if ($updatedAt.length) {
            $updatedAt.text(timestampText);
        }
    }

    function renderMilestones(records) {
        var $container = $('#client-portal-milestones');

        if (! $container.length) {
            return;
        }

        if (! Array.isArray(records) || records.length === 0) {
            $container.html('<p class="text-muted mb-0">' + escapeHtml(translate(
                'client_portal.milestones.empty',
                'No shipments available.'
            )) + '</p>');
            return;
        }

        var sorted = records.slice().sort(function (a, b) {
            var dateA = parseDate(a && a.fecha_desembarque);
            var dateB = parseDate(b && b.fecha_desembarque);

            if (dateA && dateB) {
                return dateB.getTime() - dateA.getTime();
            }

            if (dateA) {
                return -1;
            }

            if (dateB) {
                return 1;
            }

            return 0;
        });

        var html = sorted.map(function (record) {
            var reference = escapeHtml(record && record.referencia ? record.referencia : '');
            var destination = escapeHtml(record && record.destino ? record.destino : '');
            var status = escapeHtml(record && record.status_label ? record.status_label : translate('client_portal.milestones.status', 'Status'));
            var statusSlug = normalizeStatus(record);
            var statusClass = 'client-portal-status badge rounded-pill';
            var landingDate = formatDate(record && record.fecha_desembarque);
            var departureDate = formatDate(record && record.fecha_embarque);
            var noticeNumber = escapeHtml(record && record.folio_aviso ? record.folio_aviso : '');
            var daysElapsed = Number.isFinite(Number(record && record.dias_transcurridos))
                ? formatNumber(record.dias_transcurridos)
                : '0';
            var daysOut = Number.isFinite(Number(record && record.dias_fuera))
                ? formatNumber(record.dias_fuera)
                : '0';
            var confirmation = record && record.milestone_confirmation ? record.milestone_confirmation : {};
            var confirmedByUser = !! (confirmation && confirmation.confirmed_by_current_user);
            var confirmedByAny = !! (confirmation && confirmation.confirmed);
            var confirmationHtml = '';

            switch (statusSlug) {
                case 'pending':
                    statusClass += ' bg-warning-subtle text-warning';
                    break;
                case 'in_progress':
                    statusClass += ' bg-info-subtle text-info';
                    break;
                case 'completed':
                    statusClass += ' bg-success-subtle text-success';
                    break;
                case 'on_hold':
                    statusClass += ' bg-secondary-subtle text-secondary';
                    break;
                case 'cancelled':
                    statusClass += ' bg-dark-subtle text-dark';
                    break;
                default:
                    statusClass += ' bg-light text-muted';
                    break;
            }

            if (confirmedByUser) {
                confirmationHtml = '<span class="badge bg-success-subtle text-success" data-confirmation-badge>'
                    + escapeHtml(translate('client_portal.milestones.confirmed_badge', 'Delivery confirmed'))
                    + '</span>';
            } else if (statusSlug === 'completed') {
                confirmationHtml = '<button type="button" class="btn btn-success btn-sm" data-confirm-milestone="'
                    + escapeHtml(record && record.id ? record.id : '')
                    + '">' + escapeHtml(translate('client_portal.milestones.confirm_cta', 'Confirm delivery')) + '</button>';
            } else if (confirmedByAny) {
                confirmationHtml = '<span class="badge bg-success-subtle text-success" data-confirmation-badge>'
                    + escapeHtml(translate('client_portal.milestones.confirmed_badge', 'Delivery confirmed'))
                    + '</span>';
            }

            return [
                '<article class="card border-0 shadow-sm mb-3" data-milestone-id="' + escapeHtml(record && record.id ? record.id : '') + '">',
                '    <div class="card-body">',
                '        <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">',
                '            <div>',
                '                <div class="d-flex align-items-center gap-3 mb-2">',
                '                    <h3 class="h5 mb-0">' + reference + '</h3>',
                '                    <span class="' + statusClass + '">' + status + '</span>',
                '                </div>',
                '                <ul class="list-unstyled text-muted small mb-0 client-portal-milestone-meta">',
                '                    <li><strong>' + escapeHtml(translate('client_portal.milestones.destination', 'Destination')) + ':</strong> ' + destination + '</li>',
                '                    <li><strong>' + escapeHtml(translate('client_portal.milestones.landing_date', 'Unloading date')) + ':</strong> ' + escapeHtml(landingDate) + '</li>',
                '                    <li><strong>' + escapeHtml(translate('client_portal.milestones.departure_date', 'Departure date')) + ':</strong> ' + escapeHtml(departureDate) + '</li>',
                '                    <li><strong>' + escapeHtml(translate('client_portal.milestones.notice_number', 'Notice number')) + ':</strong> ' + noticeNumber + '</li>',
                '                </ul>',
                '            </div>',
                '            <div class="text-lg-end" data-milestone-actions>',
                '                <p class="mb-1"><strong>' + escapeHtml(translate('client_portal.milestones.days_elapsed', 'Days elapsed')) + ':</strong> ' + daysElapsed + '</p>',
                '                <p class="mb-3"><strong>' + escapeHtml(translate('client_portal.milestones.days_out', 'Dwell time (days)')) + ':</strong> ' + daysOut + '</p>',
                (confirmationHtml !== '' ? '                <div class="mb-3">' + confirmationHtml + '</div>' : ''),
                '                <a href="reportes.php" class="btn btn-link btn-sm text-decoration-none px-0">' + escapeHtml(translate('client_portal.milestones.view_details', 'Open in reports')) + '</a>',
                '            </div>',
                '        </div>',
                '    </div>',
                '</article>'
            ].join('');
        }).join('');

        $container.html(html);
    }

    function renderDocuments(records) {
        var $container = $('#client-portal-documents');

        if (! $container.length) {
            return;
        }

        if (! Array.isArray(records)) {
            $container.empty();
            return;
        }

        var shipmentsWithDocuments = records.filter(function (record) {
            return record && Array.isArray(record.attachments) && record.attachments.length > 0;
        });

        if (shipmentsWithDocuments.length === 0) {
            $container.html('<p class="text-muted mb-0">' + escapeHtml(translate(
                'client_portal.documents.empty',
                'No documents available yet.'
            )) + '</p>');
            return;
        }

        var html = shipmentsWithDocuments.map(function (record) {
            var reference = record && record.referencia ? record.referencia : '';
            var title = translate('client_portal.documents.shipment_label', 'Shipment {{reference}}', {
                reference: reference || '#'
            });
            var attachments = Array.isArray(record.attachments) ? record.attachments : [];

            var items = attachments.map(function (attachment) {
                var name = escapeHtml(attachment && attachment.original_name ? attachment.original_name : '');
                var size = formatFileSize(attachment && attachment.size ? attachment.size : 0);
                var downloadUrl = attachment && attachment.download_url ? String(attachment.download_url) : '#';
                var sizeLabel = translate('client_portal.documents.size', 'Size: {{size}}', { size: size });

                return [
                    '<li class="list-group-item d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2">',
                    '    <div>',
                    '        <span class="fw-semibold d-block">' + name + '</span>',
                    '        <span class="text-muted small">' + escapeHtml(sizeLabel) + '</span>',
                    '    </div>',
                    '    <a class="btn btn-outline-primary btn-sm" href="' + escapeHtml(downloadUrl) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(translate('client_portal.documents.download', 'Download')) + '</a>',
                    '</li>'
                ].join('');
            }).join('');

            return [
                '<article class="card border-0 shadow-sm mb-3">',
                '    <div class="card-body">',
                '        <h3 class="h5 mb-3">' + escapeHtml(title) + '</h3>',
                '        <ul class="list-group list-group-flush">' + items + '</ul>',
                '    </div>',
                '</article>'
            ].join('');
        }).join('');

        $container.html(html);
    }

    function renderMessages(messages) {
        var $container = $('#client-portal-messages');
        var $empty = $('#client-portal-messages-empty');

        if (! $container.length) {
            return;
        }

        messagesState = Array.isArray(messages) ? messages.slice() : [];

        if (! Array.isArray(messages) || messages.length === 0) {
            $container.empty();

            if ($empty.length) {
                $empty.removeClass('d-none');
            }

            return;
        }

        if ($empty.length) {
            $empty.addClass('d-none');
        }

        var items = messages.map(function (message) {
            var text = escapeHtml(message && message.message ? message.message : '');
            var createdAt = formatDateTime(parseDate(message && message.created_at ? message.created_at : ''));
            var author = escapeHtml(message && message.sender_name ? message.sender_name : '');
            var authorRole = escapeHtml(translateRoleLabel(message && message.sender_role ? message.sender_role : '', language));
            var metaParts = [];

            if (author !== '') {
                metaParts.push(author);
            } else if (message && message.sender_email) {
                metaParts.push(escapeHtml(message.sender_email));
            }

            if (authorRole !== '') {
                metaParts.push(authorRole);
            }

            if (createdAt !== '') {
                metaParts.push(createdAt);
            }

            var meta = metaParts.join(' · ');
            var alignment = message && message.is_author ? 'align-self-end text-end bg-primary text-white' : 'align-self-start bg-light';

            return [
                '<li class="list-group-item border-0 px-0">',
                '    <div class="d-inline-flex flex-column gap-1 p-3 rounded-3 ' + alignment + '">',
                '        <p class="mb-0">' + text + '</p>',
                '        <span class="small text-opacity-75">' + escapeHtml(meta) + '</span>',
                '    </div>',
                '</li>'
            ].join('');
        }).join('');

        $container.html(items);

        var wrapper = document.getElementById('client-portal-messages-wrapper');

        if (wrapper) {
            wrapper.scrollTop = wrapper.scrollHeight;
        }
    }

    function showError(message) {
        var $error = $('#client-portal-error');
        var $content = $('#client-portal-content');
        var $loading = $('#client-portal-loading');

        if ($loading.length) {
            $loading.addClass('d-none');
        }

        if ($content.length) {
            $content.addClass('d-none');
        }

        if ($error.length) {
            $error.text(message || translate('client_portal.error.load_failed', 'Unable to load information.'));
            $error.removeClass('d-none');
        }
    }

    function showContent() {
        var $error = $('#client-portal-error');
        var $content = $('#client-portal-content');
        var $loading = $('#client-portal-loading');

        if ($loading.length) {
            $loading.addClass('d-none');
        }

        if ($error.length) {
            $error.addClass('d-none').text('');
        }

        if ($content.length) {
            $content.removeClass('d-none');
        }
    }

    function loadPreferences() {
        var deferred = $.Deferred();

        $.ajax({
            url: '../api/client-portal/preferences.php',
            method: 'GET',
            dataType: 'json'
        }).done(function (response) {
            if (response && response.success === true && response.data && Array.isArray(response.data.widgets)) {
                activeWidgets = sanitizeWidgetList(response.data.widgets);
            } else {
                activeWidgets = defaultWidgets.slice();
            }
        }).fail(function () {
            activeWidgets = defaultWidgets.slice();
        }).always(function () {
            applyWidgetVisibility();
            deferred.resolve();
        });

        return deferred.promise();
    }

    function loadShipments() {
        var deferred = $.Deferred();

        $.ajax({
            url: '../api/desembarques/list.php',
            method: 'GET',
            dataType: 'json'
        }).done(function (response) {
            if (! response || response.success !== true || ! Array.isArray(response.data)) {
                deferred.reject();
                return;
            }

            renderSummary(response.data);
            renderMilestones(response.data);
            renderDocuments(response.data);
            deferred.resolve();
        }).fail(function () {
            deferred.reject();
        });

        return deferred.promise();
    }

    function loadMessages() {
        var deferred = $.Deferred();

        $.ajax({
            url: buildMessagesUrl(),
            method: 'GET',
            dataType: 'json'
        }).done(function (response) {
            if (response && response.success === true && Array.isArray(response.data)) {
                renderMessages(response.data);
                showMessagesFeedback('', 'success');
            } else {
                renderMessages([]);
            }

            deferred.resolve();
        }).fail(function () {
            renderMessages([]);
            showMessagesFeedback(translate('client_portal.messages.error.load', 'We could not load your messages.'), 'error');
            deferred.resolve();
        });

        return deferred.promise();
    }

    function savePreferences(widgets) {
        return $.ajax({
            url: '../api/client-portal/preferences.php',
            method: 'POST',
            contentType: 'application/json; charset=utf-8',
            dataType: 'json',
            data: JSON.stringify({ widgets: widgets })
        });
    }

    function sendMessage(message) {
        var payload = { message: message };

        if (clientUserId !== null) {
            payload.client_user_id = clientUserId;
        }

        return $.ajax({
            url: buildMessagesUrl(),
            method: 'POST',
            contentType: 'application/json; charset=utf-8',
            dataType: 'json',
            data: JSON.stringify(payload)
        });
    }

    $(function () {
        $.when(
            loadPreferences(),
            loadShipments(),
            loadMessages()
        ).done(function () {
            applyWidgetVisibility();
            showContent();
        }).fail(function () {
            showError(translate('client_portal.error.load_failed', 'Unable to load information.'));
        });

        $('#client-portal-open-customize').on('click', function (event) {
            event.preventDefault();
            displayCustomizeFeedback('', 'success');
            updateCustomizeForm();

            var modal = getCustomizeModal();

            if (modal) {
                modal.show();
            }
        });

        $('#client-portal-customize-form').on('submit', function (event) {
            event.preventDefault();

            var $form = $(this);
            var $submit = $form.find('button[type="submit"]');
            var selected = [];

            $form.find('input[name="widgets[]"]:checked').each(function () {
                var value = $(this).val();

                if (typeof value === 'string' && value.trim() !== '') {
                    selected.push(value.trim());
                }
            });

            if (selected.length === 0) {
                selected = defaultWidgets.slice();
            }

            $submit.prop('disabled', true);
            displayCustomizeFeedback('', 'success');

            savePreferences(selected).done(function (response) {
                if (response && response.success === true && response.data && Array.isArray(response.data.widgets)) {
                    activeWidgets = sanitizeWidgetList(response.data.widgets);
                } else {
                    activeWidgets = sanitizeWidgetList(selected);
                }

                applyWidgetVisibility();

                var modal = getCustomizeModal();

                if (modal) {
                    modal.hide();
                }
            }).fail(function () {
                displayCustomizeFeedback(translate('client_portal.preferences.error.save', 'We could not save your preferences.'), 'error');
            }).always(function () {
                $submit.prop('disabled', false);
            });
        });

        $('#client-portal-message-form').on('submit', function (event) {
            event.preventDefault();

            var $form = $(this);
            var $textarea = $form.find('textarea[name="message"]');
            var $submit = $form.find('button[type="submit"]');
            var message = $textarea.val();

            if (typeof message !== 'string' || message.trim() === '') {
                showMessagesFeedback(translate('validation.required', 'This field is required.'), 'error');
                return;
            }

            $submit.prop('disabled', true);
            showMessagesFeedback('', 'success');

            sendMessage(message.trim()).done(function (response) {
                if (response && response.success === true && response.data) {
                    messagesState.push(response.data);
                    renderMessages(messagesState);
                    $textarea.val('');
                    showMessagesFeedback(translate('client_portal.messages.success', 'Message sent successfully.'), 'success');
                    window.setTimeout(function () {
                        showMessagesFeedback('', 'success');
                    }, 4000);
                }
            }).fail(function () {
                showMessagesFeedback(translate('client_portal.messages.error.send', 'We could not send your message. Please try again.'), 'error');
            }).always(function () {
                $submit.prop('disabled', false);
            });
        });

        $(document).on('click', '[data-confirm-milestone]', function (event) {
            event.preventDefault();

            var $button = $(this);
            var recordId = Number($button.data('confirmMilestone'));

            if (! Number.isFinite(recordId) || recordId <= 0) {
                return;
            }

            $button.prop('disabled', true);

            $.ajax({
                url: '../api/client-portal/confirm-milestone.php',
                method: 'POST',
                contentType: 'application/json; charset=utf-8',
                dataType: 'json',
                data: JSON.stringify({ desembarque_id: recordId })
            }).done(function (response) {
                if (! response || response.success !== true) {
                    throw new Error('Request failed');
                }

                var $actions = $button.closest('[data-milestone-actions]');
                var successMessage = translate('client_portal.milestones.confirm_success', 'Thanks for confirming the delivery.');
                var badge = '<span class="badge bg-success-subtle text-success" data-confirmation-badge>'
                    + escapeHtml(translate('client_portal.milestones.confirmed_badge', 'Delivery confirmed'))
                    + '</span>';

                $button.remove();

                if ($actions && $actions.length) {
                    $actions.find('[data-confirmation-badge]').remove();
                    $actions.prepend('<div class="mb-3">' + badge + '</div>');
                    displayInlineFeedback($actions, successMessage, 'success');
                }
            }).fail(function () {
                var errorMessage = translate('client_portal.milestones.confirm_error', 'We could not record your confirmation.');
                var $actions = $button.closest('[data-milestone-actions]');
                displayInlineFeedback($actions, errorMessage, 'error');
            }).always(function () {
                $button.prop('disabled', false);
            });
        });
    });
})(jQuery);
