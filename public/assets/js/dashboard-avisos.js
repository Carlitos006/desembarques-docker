(function () {
    'use strict';

    var config = window.DashboardAvisosConfig || {};
    var labels = config.labels || {};
    var language = config.language || 'es';
    var apiUrl = config.apiUrl || '../api/desembarques/dashboard/summary.php';
    var isAdmin = Boolean(config.isAdmin);

    var app = document.getElementById('dashboard-executive-app');
    if (!app) {
        return;
    }

    var loading = app.querySelector('[data-dashboard-loading]');
    var content = app.querySelector('[data-dashboard-content]');
    var errorPanel = app.querySelector('[data-dashboard-error]');
    var errorMessage = app.querySelector('[data-dashboard-error-message]');
    var filterForm = app.querySelector('[data-dashboard-filters]');
    var resetButton = app.querySelector('[data-dashboard-reset]');
    var refreshButton = app.querySelector('[data-dashboard-refresh]');
    var integrityBadge = app.querySelector('[data-dashboard-integrity]');
    var updatedLabel = app.querySelector('[data-dashboard-updated]');
    var recentBody = app.querySelector('[data-dashboard-recent]');
    var recentEmpty = app.querySelector('[data-dashboard-recent-empty]');
    var yearSelect = app.querySelector('[data-filter="year"]');
    var dateFromInput = app.querySelector('[data-filter="date_from"]');
    var dateToInput = app.querySelector('[data-filter="date_to"]');
    var clientSelect = app.querySelector('[data-filter="client_id"]');
    var rigSelect = app.querySelector('[data-filter="rig_name"]');
    var statusSelect = app.querySelector('[data-filter="aviso_status"]');
    var originSelect = app.querySelector('[data-filter="origin"]');
    var scopeSelect = app.querySelector('[data-filter="record_scope"]');

    var charts = {
        monthly: null,
        status: null,
        pieces: null,
        aging: null
    };

    var lastPayload = null;
    var requestController = null;
    var initialQueryParams = new URLSearchParams(window.location.search);
    var pendingCatalogSelections = {
        client_id: initialQueryParams.get('client_id') || '',
        rig_name: initialQueryParams.get('rig_name') || ''
    };
    var numberFormatter = new Intl.NumberFormat(language === 'en' ? 'en-US' : 'es-MX', {
        maximumFractionDigits: 3
    });
    var dateFormatter = new Intl.DateTimeFormat(language === 'en' ? 'en-US' : 'es-MX', {
        day: '2-digit',
        month: 'short',
        year: 'numeric'
    });
    var monthFormatter = new Intl.DateTimeFormat(language === 'en' ? 'en-US' : 'es-MX', {
        month: 'short',
        year: '2-digit'
    });
    var dateTimeFormatter = new Intl.DateTimeFormat(language === 'en' ? 'en-US' : 'es-MX', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });

    function localized(english, spanish) {
        return language === 'en' ? english : spanish;
    }

    function formatNumber(value) {
        var numeric = Number(value);
        return numberFormatter.format(Number.isFinite(numeric) ? numeric : 0);
    }

    function parseDate(value) {
        if (!value) {
            return null;
        }

        var normalized = String(value).trim();
        if (!normalized) {
            return null;
        }

        var date = new Date(normalized.length === 10 ? normalized + 'T12:00:00' : normalized.replace(' ', 'T'));
        return Number.isNaN(date.getTime()) ? null : date;
    }

    function formatDate(value) {
        var date = parseDate(value);
        return date ? dateFormatter.format(date) : '—';
    }

    function formatDateTime(value) {
        var date = parseDate(value);
        return date ? dateTimeFormatter.format(date) : '—';
    }

    function formatMonth(value) {
        if (!value || !/^\d{4}-\d{2}$/.test(String(value))) {
            return String(value || '');
        }

        var parts = String(value).split('-');
        var date = new Date(Number(parts[0]), Number(parts[1]) - 1, 1, 12, 0, 0);
        return monthFormatter.format(date);
    }

    function setLoading(isLoading) {
        if (loading) {
            loading.classList.toggle('d-none', !isLoading);
        }
        if (content) {
            content.classList.toggle('d-none', isLoading);
        }
    }

    function setError(message) {
        var hasError = Boolean(message);
        if (errorPanel) {
            errorPanel.classList.toggle('d-none', !hasError);
        }
        if (errorMessage) {
            errorMessage.textContent = message || '';
        }
        if (content && hasError) {
            content.classList.add('d-none');
        }
    }

    function dashboardColors() {
        var styles = getComputedStyle(document.documentElement);
        var bodyStyles = getComputedStyle(document.body);
        var isDark = document.body.classList.contains('theme-dark');

        return {
            accent: styles.getPropertyValue('--dashboard-accent').trim() || '#d71538',
            accentStrong: styles.getPropertyValue('--dashboard-accent-strong').trim() || '#a9102c',
            success: styles.getPropertyValue('--dashboard-success').trim() || '#198754',
            warning: styles.getPropertyValue('--dashboard-warning').trim() || '#d97706',
            info: styles.getPropertyValue('--dashboard-info').trim() || '#0f6d92',
            text: styles.getPropertyValue('--dashboard-ink').trim() || bodyStyles.color || '#172033',
            muted: styles.getPropertyValue('--dashboard-muted').trim() || '#687387',
            grid: isDark ? 'rgba(255,255,255,.09)' : 'rgba(34,46,66,.08)',
            historical: isDark ? '#68c0e1' : '#0f6d92',
            system: isDark ? '#ef8398' : '#d71538',
            draft: '#6c757d',
            issued: '#0d6efd',
            presented: '#198754',
            replaced: '#d97706',
            cancelled: '#dc3545',
            stored: isDark ? '#6fc1db' : '#1687a7',
            exported: isDark ? '#70d49a' : '#198754'
        };
    }

    function chartBaseOptions() {
        var colors = dashboardColors();
        return {
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 280 },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        color: colors.muted,
                        boxWidth: 10,
                        boxHeight: 10,
                        usePointStyle: true,
                        pointStyle: 'circle',
                        padding: 16,
                        font: { size: 11 }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function (context) {
                            return (context.dataset.label ? context.dataset.label + ': ' : '') + formatNumber(context.parsed.y !== undefined ? context.parsed.y : context.parsed);
                        }
                    }
                }
            }
        };
    }

    function destroyChart(key) {
        if (charts[key] && typeof charts[key].destroy === 'function') {
            charts[key].destroy();
        }
        charts[key] = null;
    }

    function chartEmpty(key, isEmpty) {
        var empty = app.querySelector('[data-chart-empty="' + key + '"]');
        if (empty) {
            empty.classList.toggle('d-none', !isEmpty);
        }
    }

    function renderMonthlyChart(rows) {
        destroyChart('monthly');
        var canvas = document.getElementById('dashboard-chart-monthly');
        var data = Array.isArray(rows) ? rows : [];
        var hasData = data.some(function (row) { return Number(row.avisos || 0) > 0; });
        chartEmpty('monthly', !hasData);
        if (!canvas || !hasData || !window.Chart) {
            return;
        }

        var colors = dashboardColors();
        var options = chartBaseOptions();
        options.scales = {
            x: {
                stacked: true,
                ticks: { color: colors.muted, maxRotation: 0, autoSkip: true },
                grid: { display: false }
            },
            y: {
                stacked: true,
                beginAtZero: true,
                ticks: { color: colors.muted, precision: 0 },
                grid: { color: colors.grid }
            }
        };

        charts.monthly = new window.Chart(canvas, {
            type: 'bar',
            data: {
                labels: data.map(function (row) { return formatMonth(row.month); }),
                datasets: [
                    {
                        label: labels.historical || (language === 'en' ? 'Historical' : 'Histórico'),
                        data: data.map(function (row) { return Number(row.historical || 0); }),
                        backgroundColor: colors.historical,
                        borderRadius: 5,
                        stack: 'avisos'
                    },
                    {
                        label: labels.system || localized('System', 'Sistema'),
                        data: data.map(function (row) { return Number(row.system || 0); }),
                        backgroundColor: colors.system,
                        borderRadius: 5,
                        stack: 'avisos'
                    }
                ]
            },
            options: options
        });
    }

    function statusLabel(id, fallback) {
        var map = {
            draft: labels.draft || localized('Draft', 'Borrador'),
            issued: labels.issued || localized('Issued', 'Emitido'),
            presented: labels.presented || localized('Presented', 'Presentado'),
            replaced: labels.replaced || localized('Replaced', 'Reemplazado'),
            cancelled: labels.cancelled || localized('Cancelled', 'Cancelado')
        };
        return map[id] || fallback || id;
    }

    function pieceStatusLabel(id, fallback) {
        if (id === 'exported') return labels.exported || localized('Exported', 'Exportadas');
        if (id === 'stored') return labels.stored || localized('Stored', 'En almacén');
        return fallback || id;
    }

    function agingLabel(id, fallback) {
        var map = {
            '0_30': localized('0–30 days', '0 a 30 días'),
            '31_60': localized('31–60 days', '31 a 60 días'),
            '61_90': localized('61–90 days', '61 a 90 días'),
            '91_plus': localized('91+ days', '91 días o más'),
            unknown: localized('Unknown date', 'Fecha desconocida')
        };
        return map[id] || fallback || id;
    }

    function rankingDimensionLabel(value, selector) {
        var normalized = String(value || '');
        if (selector === 'clients' && normalized === 'Sin cliente') return localized('No client assigned', 'Sin cliente');
        if (selector === 'rigs' && normalized === 'Sin Rig') return localized('No rig assigned', 'Sin Rig');
        return normalized || '—';
    }

    function renderStatusChart(rows) {
        destroyChart('status');
        var canvas = document.getElementById('dashboard-chart-status');
        var data = (Array.isArray(rows) ? rows : []).filter(function (row) { return Number(row.value || 0) > 0; });
        chartEmpty('status', !data.length);
        if (!canvas || !data.length || !window.Chart) {
            return;
        }

        var colors = dashboardColors();
        var colorMap = {
            draft: colors.draft,
            issued: colors.issued,
            presented: colors.presented,
            replaced: colors.replaced,
            cancelled: colors.cancelled
        };
        var options = chartBaseOptions();
        options.cutout = '70%';
        options.plugins.tooltip.callbacks.label = function (context) {
            return context.label + ': ' + formatNumber(context.parsed);
        };

        charts.status = new window.Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: data.map(function (row) { return statusLabel(row.id, row.label); }),
                datasets: [{
                    data: data.map(function (row) { return Number(row.value || 0); }),
                    backgroundColor: data.map(function (row) { return colorMap[row.id] || colors.muted; }),
                    borderWidth: 0,
                    hoverOffset: 4
                }]
            },
            options: options
        });
    }

    function renderPiecesChart(rows) {
        destroyChart('pieces');
        var canvas = document.getElementById('dashboard-chart-pieces');
        var data = Array.isArray(rows) ? rows : [];
        var hasData = data.some(function (row) { return Number(row.value || 0) > 0; });
        chartEmpty('pieces', !hasData);
        if (!canvas || !hasData || !window.Chart) {
            return;
        }

        var colors = dashboardColors();
        var options = chartBaseOptions();
        options.cutout = '72%';
        options.plugins.tooltip.callbacks.label = function (context) {
            return context.label + ': ' + formatNumber(context.parsed);
        };

        charts.pieces = new window.Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: data.map(function (row) { return pieceStatusLabel(row.id, row.label); }),
                datasets: [{
                    data: data.map(function (row) { return Number(row.value || 0); }),
                    backgroundColor: data.map(function (row) {
                        return row.id === 'exported' ? colors.exported : colors.stored;
                    }),
                    borderWidth: 0,
                    hoverOffset: 4
                }]
            },
            options: options
        });
    }

    function renderAgingChart(rows) {
        destroyChart('aging');
        var canvas = document.getElementById('dashboard-chart-aging');
        var data = Array.isArray(rows) ? rows : [];
        var hasData = data.some(function (row) { return Number(row.pieces_stored || 0) > 0; });
        chartEmpty('aging', !hasData);
        if (!canvas || !hasData || !window.Chart) {
            return;
        }

        var colors = dashboardColors();
        var palette = [colors.success, colors.info, colors.warning, colors.accent, colors.muted];
        var options = chartBaseOptions();
        options.plugins.legend.display = false;
        options.plugins.tooltip.callbacks.label = function (context) {
            var row = data[context.dataIndex] || {};
            return formatNumber(context.parsed.y) + ' ' + (labels.pieces || localized('Pieces', 'Piezas')) + ' · ' + formatNumber(row.rows_count || 0) + ' ' + (labels.rows || localized('Merchandise lines', 'Renglones'));
        };
        options.scales = {
            x: { ticks: { color: colors.muted }, grid: { display: false } },
            y: { beginAtZero: true, ticks: { color: colors.muted }, grid: { color: colors.grid } }
        };

        charts.aging = new window.Chart(canvas, {
            type: 'bar',
            data: {
                labels: data.map(function (row) { return agingLabel(row.id, row.label); }),
                datasets: [{
                    label: labels.storedPieces || (language === 'en' ? 'Pieces in storage' : 'Piezas en almacén'),
                    data: data.map(function (row) { return Number(row.pieces_stored || 0); }),
                    backgroundColor: data.map(function (_row, index) { return palette[index % palette.length]; }),
                    borderRadius: 6,
                    maxBarThickness: 54
                }]
            },
            options: options
        });
    }

    function setKpis(kpis) {
        var source = kpis || {};
        app.querySelectorAll('[data-kpi]').forEach(function (element) {
            var key = element.getAttribute('data-kpi');
            element.textContent = formatNumber(source[key] || 0);
        });
    }

    function setCycleTimes(cycleTimes) {
        var cycle = cycleTimes || {};
        app.querySelectorAll('[data-cycle]').forEach(function (element) {
            var key = element.getAttribute('data-cycle');
            var value = cycle[key];
            element.textContent = value === null || value === undefined
                ? '—'
                : formatNumber(value) + ' ' + (labels.days || (language === 'en' ? 'days' : 'días'));
        });
        app.querySelectorAll('[data-cycle-samples]').forEach(function (element) {
            var key = element.getAttribute('data-cycle-samples');
            var value = Number(cycle[key] || 0);
            element.textContent = value ? 'n=' + formatNumber(value) : 'n=0';
        });
    }

    function setAttention(aging) {
        var target = app.querySelector('[data-dashboard-aging-90]');
        if (!target) {
            return;
        }
        var rows = Array.isArray(aging) ? aging : [];
        var bucket = rows.find(function (row) { return row.id === '91_plus'; });
        target.textContent = formatNumber(bucket ? bucket.pieces_stored : 0);
    }

    function renderRanking(selector, rows) {
        var container = app.querySelector('[data-ranking="' + selector + '"]');
        if (!container) {
            return;
        }
        container.replaceChildren();
        var data = Array.isArray(rows) ? rows.slice(0, 8) : [];
        if (!data.length) {
            var empty = document.createElement('div');
            empty.className = 'dashboard-empty-table';
            empty.textContent = labels.noData || localized('No data available.', 'No hay datos.');
            container.appendChild(empty);
            return;
        }

        var maxAvisos = Math.max.apply(null, data.map(function (row) { return Number(row.avisos || 0); }).concat([1]));

        data.forEach(function (row) {
            var item = document.createElement('div');
            item.className = 'dashboard-ranking-item dashboard-ranking-item--interactive';
            item.tabIndex = 0;
            item.setAttribute('role', 'button');
            item.dataset.dashboardRanking = selector;
            item.dataset.dashboardRankingValue = row.label || '';

            var main = document.createElement('div');
            main.className = 'dashboard-ranking-main';
            var label = document.createElement('strong');
            var displayLabel = rankingDimensionLabel(row.label, selector);
            label.textContent = displayLabel;
            label.title = displayLabel;
            var count = document.createElement('span');
            count.textContent = formatNumber(row.avisos || 0) + ' ' + (labels.avisos || localized('Notices', 'Avisos'));
            main.append(label, count);

            var bar = document.createElement('div');
            bar.className = 'dashboard-ranking-bar';
            var fill = document.createElement('span');
            fill.style.width = Math.max(4, Math.round((Number(row.avisos || 0) / maxAvisos) * 100)) + '%';
            bar.appendChild(fill);

            var meta = document.createElement('div');
            meta.className = 'dashboard-ranking-meta';
            var original = document.createElement('span');
            original.textContent = (labels.pieces || localized('Pieces', 'Piezas')) + ': ' + formatNumber(row.pieces_original || 0);
            var stored = document.createElement('span');
            stored.textContent = (labels.stored || (language === 'en' ? 'Stored' : 'Almacén')) + ': ' + formatNumber(row.pieces_stored || 0);
            meta.append(original, stored);

            item.append(main, bar, meta);
            container.appendChild(item);
        });
    }

    function originBadge(origin) {
        var normalized = origin === 'historical' ? 'historical' : 'system';
        var span = document.createElement('span');
        span.className = 'dashboard-origin-badge dashboard-origin-badge--' + normalized;
        span.textContent = normalized === 'historical'
            ? (labels.historical || (language === 'en' ? 'Historical' : 'Histórico'))
            : (labels.system || (language === 'en' ? 'System' : 'Sistema'));
        return span;
    }

    function statusBadge(status) {
        var normalized = ['draft', 'issued', 'presented', 'replaced', 'cancelled'].indexOf(status) >= 0 ? status : 'draft';
        var span = document.createElement('span');
        span.className = 'dashboard-status-badge dashboard-status-badge--' + normalized;
        span.textContent = statusLabel(normalized, normalized);
        return span;
    }

    function renderRecent(rows) {
        if (!recentBody) {
            return;
        }
        recentBody.replaceChildren();
        var data = Array.isArray(rows) ? rows : [];
        if (recentEmpty) {
            recentEmpty.classList.toggle('d-none', data.length > 0);
        }
        if (!data.length) {
            return;
        }

        data.forEach(function (row) {
            var tr = document.createElement('tr');

            var noticeTd = document.createElement('td');
            var notice = document.createElement('div');
            notice.className = 'dashboard-notice-code';
            notice.textContent = row.notice_number || row.document_code || ('#' + row.id);
            var code = document.createElement('div');
            code.className = 'dashboard-subtle';
            code.textContent = row.document_code || '';
            noticeTd.append(notice, code);

            var dateTd = document.createElement('td');
            dateTd.textContent = formatDate(row.metric_date);

            var clientTd = document.createElement('td');
            clientTd.textContent = row.client_name || '—';

            var rigTd = document.createElement('td');
            var rig = document.createElement('div');
            rig.textContent = row.rig_name || '—';
            var field = document.createElement('div');
            field.className = 'dashboard-subtle';
            field.textContent = row.rig_field || '';
            rigTd.append(rig, field);

            var originTd = document.createElement('td');
            originTd.appendChild(originBadge(row.origin));

            var statusTd = document.createElement('td');
            statusTd.appendChild(statusBadge(row.aviso_status));

            var versionTd = document.createElement('td');
            if (row.latest_version_no) {
                var version = document.createElement('div');
                version.textContent = 'v' + String(row.latest_version_no).padStart(3, '0');
                version.className = 'fw-semibold';
                var versionDate = document.createElement('div');
                versionDate.className = 'dashboard-subtle';
                versionDate.textContent = formatDateTime(row.latest_version_at);
                versionTd.append(version, versionDate);
            } else {
                versionTd.textContent = '—';
            }

            var actionTd = document.createElement('td');
            actionTd.className = 'text-end';
            var link = document.createElement('a');
            link.className = 'btn btn-sm btn-outline-primary';
            link.href = 'aviso-expediente.php?id=' + encodeURIComponent(row.id);
            link.textContent = labels.openCase || localized('Open case file', 'Abrir expediente');
            actionTd.appendChild(link);

            tr.append(noticeTd, dateTd, clientTd, rigTd, originTd, statusTd, versionTd, actionTd);
            recentBody.appendChild(tr);
        });
    }

    function selectOptionExists(select, value) {
        if (!select) {
            return false;
        }
        return Array.prototype.some.call(select.options, function (option) { return option.value === String(value); });
    }

    function fillSelect(select, options, placeholder, valueKey) {
        if (!select) {
            return;
        }
        var current = select.value;
        select.replaceChildren();
        var first = document.createElement('option');
        first.value = '';
        first.textContent = placeholder;
        select.appendChild(first);
        (Array.isArray(options) ? options : []).forEach(function (row) {
            var option = document.createElement('option');
            var value = row[valueKey] !== undefined ? row[valueKey] : row.value;
            option.value = String(value || '');
            option.textContent = row.label || String(value || '');
            select.appendChild(option);
        });
        if (current && selectOptionExists(select, current)) {
            select.value = current;
        }
    }

    function fillCatalog(catalog) {
        var data = catalog || {};
        if (yearSelect) {
            var selectedYear = yearSelect.value;
            yearSelect.querySelectorAll('option:not(:first-child)').forEach(function (option) { option.remove(); });
            (Array.isArray(data.years) ? data.years : []).forEach(function (year) {
                var option = document.createElement('option');
                option.value = String(year);
                option.textContent = String(year);
                yearSelect.appendChild(option);
            });
            if (selectedYear && selectOptionExists(yearSelect, selectedYear)) {
                yearSelect.value = selectedYear;
            }
        }
        fillSelect(clientSelect, data.clients || [], labels.allClients || localized('All clients', 'Todos los clientes'), 'id');
        fillSelect(rigSelect, data.rigs || [], labels.allRigs || localized('All rigs', 'Todos los Rigs'), 'value');

        if (pendingCatalogSelections.client_id && clientSelect && selectOptionExists(clientSelect, pendingCatalogSelections.client_id)) {
            clientSelect.value = pendingCatalogSelections.client_id;
            pendingCatalogSelections.client_id = '';
        }
        if (pendingCatalogSelections.rig_name && rigSelect && selectOptionExists(rigSelect, pendingCatalogSelections.rig_name)) {
            rigSelect.value = pendingCatalogSelections.rig_name;
            pendingCatalogSelections.rig_name = '';
        }
    }

    function updateUpdatedLabel(value) {
        if (!updatedLabel) {
            return;
        }
        var formatted = formatDateTime(value);
        updatedLabel.textContent = (labels.updated || localized('Updated', 'Actualizado')) + ': ' + formatted;
    }

    function updateIntegrity(integrity) {
        var pass = Boolean(integrity && integrity.pass);
        if (integrityBadge) {
            integrityBadge.classList.toggle('is-invalid', !pass);
        }
    }

    function render(payload) {
        lastPayload = payload || {};
        var kpis = lastPayload.kpis || {};
        var breakdowns = lastPayload.breakdowns || {};
        var series = lastPayload.series || {};
        var rankings = lastPayload.rankings || {};

        setKpis(kpis);
        setCycleTimes(lastPayload.cycle_times || {});
        setAttention(series.aging || []);
        updateIntegrity(lastPayload.integrity || {});
        updateUpdatedLabel(lastPayload.generated_at);
        fillCatalog(lastPayload.filter_catalog || {});

        renderMonthlyChart(series.monthly || []);
        renderStatusChart(breakdowns.status || []);
        renderPiecesChart(breakdowns.pieces || []);
        renderAgingChart(series.aging || []);
        renderRanking('clients', rankings.clients || []);
        renderRanking('rigs', rankings.rigs || []);
        renderRecent(lastPayload.recent_avisos || []);
        document.dispatchEvent(new CustomEvent('dashboard:data-ready', { detail: { payload: lastPayload } }));
    }

    function getFilterValues() {
        var filters = {};
        if (dateFromInput && dateFromInput.value) filters.date_from = dateFromInput.value;
        if (dateToInput && dateToInput.value) filters.date_to = dateToInput.value;
        var clientValue = clientSelect && clientSelect.value ? clientSelect.value : pendingCatalogSelections.client_id;
        var rigValue = rigSelect && rigSelect.value ? rigSelect.value : pendingCatalogSelections.rig_name;
        if (clientValue) filters.client_id = clientValue;
        if (rigValue) filters.rig_name = rigValue;
        if (statusSelect && statusSelect.value && statusSelect.value !== 'all') filters.aviso_status = statusSelect.value;
        if (originSelect && originSelect.value && originSelect.value !== 'all') filters.origin = originSelect.value;
        if (isAdmin && scopeSelect && scopeSelect.value && scopeSelect.value !== 'production') filters.record_scope = scopeSelect.value;
        return filters;
    }

    function updateBrowserUrl(filters) {
        var params = new URLSearchParams();
        Object.keys(filters).forEach(function (key) {
            if (filters[key] !== null && filters[key] !== undefined && filters[key] !== '') {
                params.set(key, filters[key]);
            }
        });
        var query = params.toString();
        var target = window.location.pathname + (query ? '?' + query : '');
        window.history.replaceState({}, '', target);
    }

    function loadDashboard(options) {
        options = options || {};
        var filters = getFilterValues();
        if (!options.skipUrl) {
            updateBrowserUrl(filters);
        }

        if (requestController) {
            requestController.abort();
        }
        requestController = new AbortController();
        var url = new URL(apiUrl, window.location.href);
        Object.keys(filters).forEach(function (key) {
            url.searchParams.set(key, filters[key]);
        });

        setError('');
        setLoading(true);
        if (refreshButton) refreshButton.disabled = true;

        fetch(url.toString(), {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json' },
            signal: requestController.signal
        })
            .then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (json) {
                    if (!response.ok || !json || json.success !== true) {
                        var message = json && json.message ? json.message : (labels.errorGeneric || (language === 'en' ? 'The executive summary could not be loaded.' : 'No fue posible cargar el resumen ejecutivo.'));
                        var error = new Error(message);
                        error.status = response.status;
                        throw error;
                    }
                    return json.data || {};
                });
            })
            .then(function (data) {
                render(data);
                setLoading(false);
                if (content) content.classList.remove('d-none');
            })
            .catch(function (error) {
                if (error && error.name === 'AbortError') {
                    return;
                }
                setLoading(false);
                setError(error && error.message ? error.message : (labels.errorGeneric || (language === 'en' ? 'The executive summary could not be loaded.' : 'No fue posible cargar el resumen ejecutivo.')));
            })
            .finally(function () {
                if (refreshButton) refreshButton.disabled = false;
            });
    }

    function resetFilters() {
        pendingCatalogSelections.client_id = '';
        pendingCatalogSelections.rig_name = '';
        if (filterForm) filterForm.reset();
        if (statusSelect) statusSelect.value = 'all';
        if (originSelect) originSelect.value = 'all';
        if (scopeSelect) scopeSelect.value = 'production';
        loadDashboard();
    }

    function applyQueryString() {
        var params = new URLSearchParams(window.location.search);
        var mapping = {
            date_from: dateFromInput,
            date_to: dateToInput,
            aviso_status: statusSelect,
            origin: originSelect,
            record_scope: scopeSelect
        };
        Object.keys(mapping).forEach(function (key) {
            var element = mapping[key];
            var value = params.get(key);
            if (element && value !== null) {
                element.value = value;
            }
        });
    }

    if (filterForm) {
        filterForm.addEventListener('submit', function (event) {
            event.preventDefault();
            loadDashboard();
        });
    }

    if (resetButton) {
        resetButton.addEventListener('click', resetFilters);
    }

    if (refreshButton) {
        refreshButton.addEventListener('click', function () { loadDashboard({ skipUrl: true }); });
    }

    if (yearSelect) {
        yearSelect.addEventListener('change', function () {
            var year = yearSelect.value;
            if (year) {
                if (dateFromInput) dateFromInput.value = year + '-01-01';
                if (dateToInput) dateToInput.value = year + '-12-31';
            } else {
                if (dateFromInput) dateFromInput.value = '';
                if (dateToInput) dateToInput.value = '';
            }
        });
    }

    [dateFromInput, dateToInput].forEach(function (input) {
        if (!input) return;
        input.addEventListener('change', function () {
            if (yearSelect) yearSelect.value = '';
        });
    });

    function applyDrilldown(type, value) {
        if (type === 'status') {
            if (statusSelect) statusSelect.value = value || 'all';
            loadDashboard();
            return;
        }
        if (type === 'client') {
            if (clientSelect) {
                var option = Array.prototype.find.call(clientSelect.options, function (candidate) {
                    return candidate.textContent === value || candidate.value === String(value);
                });
                if (option) clientSelect.value = option.value;
            }
            loadDashboard();
            return;
        }
        if (type === 'rig') {
            if (rigSelect && selectOptionExists(rigSelect, value)) rigSelect.value = value;
            loadDashboard();
        }
    }

    app.addEventListener('click', function (event) {
        var drill = event.target.closest('[data-dashboard-drill]');
        if (drill) {
            var value = drill.dataset.dashboardDrill || 'all';
            if (statusSelect) statusSelect.value = value === 'all' ? 'all' : value;
            loadDashboard();
            return;
        }
        var ranking = event.target.closest('[data-dashboard-ranking]');
        if (ranking) {
            applyDrilldown(ranking.dataset.dashboardRanking === 'clients' ? 'client' : 'rig', ranking.dataset.dashboardRankingValue || '');
        }
    });

    app.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        var target = event.target.closest('[data-dashboard-drill], [data-dashboard-ranking]');
        if (!target) return;
        event.preventDefault();
        target.click();
    });

    window.DashboardAvisosRuntime = {
        getPayload: function () { return lastPayload; },
        getFilters: function () { return getFilterValues(); },
        getCharts: function () { return charts; },
        refresh: function () { return loadDashboard({ skipUrl: true }); },
        applyStatus: function (status) { applyDrilldown('status', status); },
        isIntegrityValid: function () { return Boolean(lastPayload && lastPayload.integrity && lastPayload.integrity.pass); }
    };

    document.dispatchEvent(new CustomEvent('dashboard:runtime-ready'));

    var themeObserver = new MutationObserver(function (mutations) {
        var themeChanged = mutations.some(function (mutation) { return mutation.attributeName === 'class'; });
        if (themeChanged && lastPayload) {
            window.requestAnimationFrame(function () {
                renderMonthlyChart((lastPayload.series || {}).monthly || []);
                renderStatusChart((lastPayload.breakdowns || {}).status || []);
                renderPiecesChart((lastPayload.breakdowns || {}).pieces || []);
                renderAgingChart((lastPayload.series || {}).aging || []);
            });
        }
    });
    themeObserver.observe(document.body, { attributes: true, attributeFilter: ['class'] });

    applyQueryString();
    loadDashboard({ skipUrl: true });
}());
