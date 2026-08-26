(function ($) {
    'use strict';

    var analyticsConfig = window.AnalyticsConfig || {};
    var translations = analyticsConfig.translations || {};
    var language = analyticsConfig.language || 'es';
    var defaultRange = Number(analyticsConfig.defaultRange || 60);
    var availableWidgets = Array.isArray(analyticsConfig.widgets) ? analyticsConfig.widgets : [];
    var storageKey = 'analytics.widgets.selected';
    var chartLibraryLoaded = typeof window.Chart === 'function';
    var slaComplianceChart = null;
    var slaTrendChart = null;
    var dockCapacityChart = null;
    var numberFormatter;
    var percentFormatter;

    try {
        numberFormatter = new Intl.NumberFormat(language, { maximumFractionDigits: 1 });
    } catch (error) {
        numberFormatter = new Intl.NumberFormat('es', { maximumFractionDigits: 1 });
    }

    try {
        percentFormatter = new Intl.NumberFormat(language, { style: 'percent', maximumFractionDigits: 1, minimumFractionDigits: 0 });
    } catch (error) {
        percentFormatter = new Intl.NumberFormat('es', { style: 'percent', maximumFractionDigits: 1, minimumFractionDigits: 0 });
    }

    function translate(key, fallback, replacements) {
        var value;

        if (Object.prototype.hasOwnProperty.call(translations, key)) {
            value = translations[key];
        } else if (fallback !== undefined) {
            value = fallback;
        } else {
            value = '';
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

        return String(value);
    }

    function formatNumber(value, fractionDigits) {
        var numericValue = Number(value);

        if (!Number.isFinite(numericValue)) {
            numericValue = 0;
        }

        if (typeof fractionDigits === 'number') {
            try {
                var customFormatter = new Intl.NumberFormat(language, { maximumFractionDigits: fractionDigits, minimumFractionDigits: fractionDigits });
                return customFormatter.format(numericValue);
            } catch (error) {
                return numericValue.toFixed(fractionDigits);
            }
        }

        return numberFormatter.format(numericValue);
    }

    function formatPercent(value) {
        var numericValue = Number(value);

        if (!Number.isFinite(numericValue)) {
            numericValue = 0;
        }

        return percentFormatter.format(numericValue);
    }

    function formatDate(value) {
        if (!value) {
            return '';
        }

        try {
            var date = new Date(value + 'T00:00:00');
            return date.toLocaleDateString(language, { day: '2-digit', month: 'short' });
        } catch (error) {
            return value;
        }
    }

    function formatDateTime(value) {
        if (!value) {
            return '';
        }

        try {
            var date = new Date(value);
            return date.toLocaleString(language, { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
        } catch (error) {
            return value;
        }
    }

    function getDefaultWidgets() {
        if (!availableWidgets.length) {
            return [];
        }

        var defaults = availableWidgets
            .filter(function (widget) {
                return widget && widget.default_enabled;
            })
            .map(function (widget) {
                return widget.id;
            })
            .filter(function (id) {
                return typeof id === 'string' && id !== '';
            });

        return defaults.length ? defaults : availableWidgets.map(function (widget) { return widget.id; });
    }

    function loadWidgetPreferences() {
        try {
            var stored = window.localStorage.getItem(storageKey);

            if (!stored) {
                return getDefaultWidgets();
            }

            var parsed = JSON.parse(stored);

            if (Array.isArray(parsed)) {
                return parsed.filter(function (id) {
                    return typeof id === 'string' && id !== '';
                });
            }
        } catch (error) {
            // Ignore storage errors and use defaults.
        }

        return getDefaultWidgets();
    }

    function saveWidgetPreferences(widgetIds) {
        try {
            window.localStorage.setItem(storageKey, JSON.stringify(widgetIds));
        } catch (error) {
            // Ignore persistence errors.
        }
    }

    function applyWidgetVisibility(widgetIds) {
        var selectedSet = {};

        widgetIds.forEach(function (widgetId) {
            selectedSet[widgetId] = true;
        });

        $('[data-widget]').each(function () {
            var $element = $(this);
            var widgetId = String($element.data('widget') || '');

            if (widgetId && selectedSet[widgetId]) {
                $element.removeClass('d-none');
            } else {
                $element.addClass('d-none');
            }
        });
    }

    function toggleAlert($alert, type, message) {
        if (!$alert || !$alert.length) {
            return;
        }

        if (!message) {
            $alert.addClass('d-none').removeClass('alert-info alert-danger alert-warning alert-success');
            $alert.text('');
            return;
        }

        $alert
            .removeClass('d-none alert-info alert-danger alert-warning alert-success')
            .addClass('alert-' + type)
            .text(message);
    }

    function setLoadingState($loading, $content, isLoading) {
        if (isLoading) {
            $loading.removeClass('d-none');
            $content.addClass('d-none');
        } else {
            $loading.addClass('d-none');
            $content.removeClass('d-none');
        }
    }

    function updateRangeSummary($element, rangeInfo) {
        if (!$element || !$element.length) {
            return;
        }

        if (!rangeInfo) {
            $element.text('');
            return;
        }

        var summaryText = translate('analytics.summary.range', '', {
            start: rangeInfo.start_date || '',
            end: rangeInfo.end_date || '',
            days: rangeInfo.requested_days || ''
        });

        $element.text(summaryText);
    }

    function updateSummaryCards(analytics) {
        var sla = analytics && analytics.sla ? analytics.sla : {};
        var projections = analytics && analytics.projections ? analytics.projections : {};
        var complianceRate = sla && typeof sla.compliance_rate === 'number' ? sla.compliance_rate : 0;
        var targetHours = sla && typeof sla.target_hours === 'number' ? sla.target_hours : 0;
        var targetDays = sla && typeof sla.target_days === 'number' ? sla.target_days : 0;
        var averageDiasTranscurridos = sla && typeof sla.average_dias_transcurridos === 'number' ? sla.average_dias_transcurridos : 0;
        var averageDiasFuera = sla && typeof sla.average_dias_fuera === 'number' ? sla.average_dias_fuera : 0;
        var totalRecords = sla && typeof sla.total_records === 'number' ? sla.total_records : 0;
        var projectedTotal = projections && typeof projections.expected_total === 'number' ? projections.expected_total : 0;
        var trendKey = projections && typeof projections.trend === 'string' ? projections.trend : 'stable';

        $('[data-analytics="sla-compliance"]').text(formatPercent(complianceRate));

        var targetText = translate('analytics.summary.target', 'Target: ≤ {{hours}} h', {
            hours: formatNumber(targetHours, 0),
            days: formatNumber(targetDays, 1)
        });
        $('[data-analytics="sla-target"]').text(targetText);

        $('[data-analytics="avg-cycle-time"]').text(formatNumber(averageDiasTranscurridos, 1));
        var cycleHelp = translate('analytics.summary.avg_cycle_help', '', {
            total: formatNumber(totalRecords, 0)
        });
        $('[data-analytics="avg-cycle-help"]').text(cycleHelp);

        $('[data-analytics="avg-days-out"]').text(formatNumber(averageDiasFuera, 1));
        var daysOutHelp = translate('analytics.summary.avg_days_out_help', '', {
            total: formatNumber(totalRecords, 0)
        });
        $('[data-analytics="avg-days-out-help"]').text(daysOutHelp);

        $('[data-analytics="projected-total"]').text(formatNumber(projectedTotal, 0));
        var trendLabel = translate('analytics.summary.trend.' + trendKey, translate('analytics.summary.trend.stable', 'Stable'));
        $('[data-analytics="trend-label"]').text(trendLabel);
    }

    function destroyChart(chartInstance) {
        if (chartInstance && typeof chartInstance.destroy === 'function') {
            chartInstance.destroy();
        }

        return null;
    }

    function toggleChartEmpty($element, show) {
        if (!$element || !$element.length) {
            return;
        }

        if (show) {
            $element.removeClass('d-none');
        } else {
            $element.addClass('d-none');
        }
    }

    function buildSlaComplianceChart(sla) {
        if (!chartLibraryLoaded) {
            return;
        }

        var compliant = sla && typeof sla.compliance_rate === 'number' ? sla.compliance_rate : 0;
        var totalRecords = sla && typeof sla.total_records === 'number' ? sla.total_records : 0;
        var breachCount = sla && typeof sla.breach_count === 'number' ? sla.breach_count : 0;
        var complianceCount = Math.max(0, totalRecords - breachCount);
        var ctx = document.getElementById('analytics-chart-sla-compliance');
        var $emptyMessage = $('[data-chart-empty="sla-compliance"]');

        if (!ctx) {
            return;
        }

        if (totalRecords <= 0) {
            slaComplianceChart = destroyChart(slaComplianceChart);
            toggleChartEmpty($emptyMessage, true);
            return;
        }

        toggleChartEmpty($emptyMessage, false);
        slaComplianceChart = destroyChart(slaComplianceChart);

        slaComplianceChart = new window.Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: [
                    translate('analytics.widgets.sla-overview.chart_compliance', 'Within SLA'),
                    translate('analytics.widgets.sla-overview.breaches_title', 'Breaches')
                ],
                datasets: [{
                    data: [complianceCount, breachCount],
                    backgroundColor: ['#198754', '#dc3545'],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                var label = context.label || '';
                                var value = context.parsed;
                                var percentage = totalRecords > 0 ? value / totalRecords : 0;
                                return label + ': ' + formatNumber(value, 0) + ' (' + formatPercent(percentage) + ')';
                            }
                        }
                    }
                }
            }
        });
    }

    function buildSlaTrendChart(sla) {
        if (!chartLibraryLoaded) {
            return;
        }

        var trend = Array.isArray(sla && sla.trend) ? sla.trend : [];
        var ctx = document.getElementById('analytics-chart-sla-trend');
        var $emptyMessage = $('[data-chart-empty="sla-trend"]');

        if (!ctx) {
            return;
        }

        if (!trend.length) {
            slaTrendChart = destroyChart(slaTrendChart);
            toggleChartEmpty($emptyMessage, true);
            return;
        }

        toggleChartEmpty($emptyMessage, false);
        slaTrendChart = destroyChart(slaTrendChart);

        var labels = trend.map(function (item) {
            return item.label || item.week;
        });
        var values = trend.map(function (item) {
            return Number(item.compliance || 0);
        });

        slaTrendChart = new window.Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: translate('analytics.widgets.sla-overview.chart_trend', 'Trend'),
                    data: values,
                    fill: false,
                    borderColor: '#0d6efd',
                    backgroundColor: '#0d6efd',
                    tension: 0.3,
                    yAxisID: 'y'
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        min: 0,
                        max: 1,
                        ticks: {
                            callback: function (value) {
                                return formatPercent(value);
                            }
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                return formatPercent(context.parsed.y);
                            }
                        }
                    }
                }
            }
        });
    }

    function renderSlaBreaches(sla) {
        var $tbody = $('#analytics-sla-breaches');

        if (!$tbody.length) {
            return;
        }

        var breaches = Array.isArray(sla && sla.breaches) ? sla.breaches : [];

        if (!breaches.length) {
            $tbody.html('<tr data-empty><td colspan="4" class="text-muted">' + translate('analytics.widgets.sla-overview.no_breaches', 'No breaches') + '</td></tr>');
            return;
        }

        var rowsHtml = breaches.map(function (breach) {
            var destination = breach.destino || '';
            var reference = breach.referencia || ('#' + breach.id);
            var diasTranscurridos = formatNumber(breach.dias_transcurridos, 0);
            var diasFuera = formatNumber(breach.dias_fuera, 0);
            var statusLabel = breach.status && breach.status.label ? breach.status.label : '';
            var severity = breach.severity === 'critical' ? 'text-danger' : 'text-warning';
            var subtitle = destination ? '<div class="text-muted small">' + destination + '</div>' : '';

            return '<tr>' +
                '<td><strong>' + reference + '</strong>' + subtitle + '</td>' +
                '<td class="text-end ' + severity + '">' + diasTranscurridos + '</td>' +
                '<td class="text-end">' + diasFuera + '</td>' +
                '<td>' + statusLabel + '</td>' +
                '</tr>';
        }).join('');

        $tbody.html(rowsHtml);
    }

    function renderSlaStatusBreakdown(sla) {
        var $list = $('#analytics-sla-status-breakdown');

        if (!$list.length) {
            return;
        }

        var items = Array.isArray(sla && sla.breaches_by_status) ? sla.breaches_by_status : [];

        if (!items.length) {
            $list.html('<li class="list-inline-item text-muted">' + translate('analytics.widgets.sla-overview.no_breaches', 'No breaches') + '</li>');
            return;
        }

        var html = items.map(function (item) {
            var label = item.label || '';
            var count = formatNumber(item.count || 0, 0);
            return '<li class="list-inline-item badge text-bg-light me-2 mb-2">' + label + ': ' + count + '</li>';
        }).join('');

        $list.html(html);
    }

    function buildDockCapacityChart(dockCapacity) {
        if (!chartLibraryLoaded) {
            return;
        }

        var docks = Array.isArray(dockCapacity && dockCapacity.docks) ? dockCapacity.docks : [];
        var ctx = document.getElementById('analytics-chart-dock-capacity');
        var $emptyMessage = $('[data-chart-empty="dock-capacity"]');

        if (!ctx) {
            return;
        }

        if (!docks.length) {
            dockCapacityChart = destroyChart(dockCapacityChart);
            toggleChartEmpty($emptyMessage, true);
            return;
        }

        toggleChartEmpty($emptyMessage, false);
        dockCapacityChart = destroyChart(dockCapacityChart);

        var labels = docks.map(function (dock) { return dock.name || dock.slug; });
        var activeData = docks.map(function (dock) { return Number(dock.active || 0); });
        var capacityData = docks.map(function (dock) {
            var capacity = Number(dock.capacity || 0);
            var active = Number(dock.active || 0);
            return Math.max(capacity - active, 0);
        });

        dockCapacityChart = new window.Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: translate('analytics.widgets.dock-capacity.table.active', 'Active'),
                        data: activeData,
                        backgroundColor: '#0d6efd',
                        stack: 'capacity'
                    },
                    {
                        label: translate('analytics.widgets.dock-capacity.table.capacity', 'Available'),
                        data: capacityData,
                        backgroundColor: '#ced4da',
                        stack: 'capacity'
                    }
                ]
            },
            options: {
                responsive: true,
                scales: {
                    x: {
                        stacked: true
                    },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                return context.dataset.label + ': ' + formatNumber(context.parsed.y, 0);
                            }
                        }
                    }
                }
            }
        });
    }

    function renderDockCapacityTable(dockCapacity) {
        var $tbody = $('#analytics-dock-capacity');

        if (!$tbody.length) {
            return;
        }

        var docks = Array.isArray(dockCapacity && dockCapacity.docks) ? dockCapacity.docks : [];

        if (!docks.length) {
            $tbody.html('<tr data-empty><td colspan="4" class="text-muted">' + translate('analytics.widgets.dock-capacity.no_data', 'No data available') + '</td></tr>');
            return;
        }

        var rows = docks.map(function (dock) {
            var status = dock.status || 'normal';
            var badgeClass = 'text-bg-secondary';

            if (status === 'warning') {
                badgeClass = 'text-bg-warning';
            } else if (status === 'critical') {
                badgeClass = 'text-bg-danger';
            }

            var utilizationPercent = formatPercent(dock.utilization || 0);
            var utilizationBadge = '<span class="badge ' + badgeClass + '">' + utilizationPercent + '</span>';

            return '<tr>' +
                '<td>' + (dock.name || dock.slug) + '</td>' +
                '<td class="text-end">' + formatNumber(dock.capacity || 0, 0) + '</td>' +
                '<td class="text-end">' + formatNumber(dock.active || 0, 0) + '</td>' +
                '<td class="text-end">' + utilizationBadge + '</td>' +
                '</tr>';
        }).join('');

        $tbody.html(rows);
    }

    function buildWeatherSection(weather) {
        var $container = $('#analytics-weather-container');
        var $emptyMessage = $('[data-weather-empty]');
        var updatedAt = weather && weather.generated_at ? formatDateTime(weather.generated_at) : '';

        if (!$container.length) {
            return;
        }

        $container.empty();

        if (!weather || !Array.isArray(weather.locations) || !weather.locations.length) {
            if ($emptyMessage.length) {
                $emptyMessage.removeClass('d-none');
            }

            $('#analytics-weather-updated').text('');
            return;
        }

        if ($emptyMessage.length) {
            $emptyMessage.addClass('d-none');
        }

        $('#analytics-weather-updated').text(translate('analytics.widgets.weather-forecast.updated', 'Updated {{timestamp}}', { timestamp: updatedAt }));

        var weatherHtml = weather.locations.map(function (location) {
            var forecasts = Array.isArray(location.forecasts) ? location.forecasts : [];
            var rows = forecasts.map(function (forecast) {
                var dateLabel = formatDate(forecast.date);
                var conditionKey = 'analytics.weather.condition.' + String(forecast.condition || '');
                var conditionLabel = translate(conditionKey, forecast.condition || '');
                var temperature = formatNumber(forecast.temperature_max || 0, 1) + '° / ' + formatNumber(forecast.temperature_min || 0, 1) + '°C';
                var wind = formatNumber(forecast.wind_speed || 0, 1) + ' km/h';
                var rain = formatPercent(forecast.rain_probability || 0);

                return '<tr>' +
                    '<td>' + dateLabel + '</td>' +
                    '<td>' + conditionLabel + '</td>' +
                    '<td>' + temperature + '</td>' +
                    '<td>' + wind + '</td>' +
                    '<td>' + rain + '</td>' +
                    '</tr>';
            }).join('');

            if (!rows) {
                rows = '<tr><td colspan="5" class="text-muted">' + translate('analytics.widgets.weather-forecast.no_data', 'No data available') + '</td></tr>';
            }

            return '<div class="table-responsive mb-4">' +
                '<h4 class="h6">' + (location.name || location.code) + '</h4>' +
                '<table class="table table-sm align-middle">' +
                '<thead class="table-light"><tr>' +
                '<th>' + translate('analytics.widgets.weather-forecast.table.date', 'Date') + '</th>' +
                '<th>' + translate('analytics.widgets.weather-forecast.table.condition', 'Condition') + '</th>' +
                '<th>' + translate('analytics.widgets.weather-forecast.table.temperature', 'Temperature') + '</th>' +
                '<th>' + translate('analytics.widgets.weather-forecast.table.wind', 'Wind') + '</th>' +
                '<th>' + translate('analytics.widgets.weather-forecast.table.rain', 'Rain') + '</th>' +
                '</tr></thead>' +
                '<tbody>' + rows + '</tbody>' +
                '</table>' +
                '</div>';
        }).join('');

        $container.html(weatherHtml);
    }

    function updateAnalyticsDashboard(response) {
        var analytics = response.analytics || {};
        updateSummaryCards(analytics);
        buildSlaComplianceChart(analytics.sla);
        buildSlaTrendChart(analytics.sla);
        renderSlaBreaches(analytics.sla);
        renderSlaStatusBreakdown(analytics.sla);
        buildDockCapacityChart(analytics.dock_capacity);
        renderDockCapacityTable(analytics.dock_capacity);
        buildWeatherSection(analytics.weather);
    }

    function syncWidgetToggles(widgetIds) {
        $('[data-widget-toggle]').each(function () {
            var $input = $(this);
            var widgetId = String($input.val() || '');

            if (widgetId === '') {
                return;
            }

            $input.prop('checked', widgetIds.indexOf(widgetId) !== -1);
        });
    }

    $(function () {
        var $filtersForm = $('#analytics-filters');
        var $rangeSelect = $('#analytics-range');
        var $alert = $('#analytics-alert');
        var $loading = $('#analytics-loading');
        var $content = $('#analytics-content');
        var $rangeSummary = $('#analytics-range-summary');
        var isLoading = false;
        var widgetSelection = loadWidgetPreferences();

        syncWidgetToggles(widgetSelection);
        applyWidgetVisibility(widgetSelection);

        $filtersForm.on('change', function (event) {
            if (event.target && event.target.id === 'analytics-range') {
                fetchAnalytics();
            }
        });

        $('#analytics-widget-selector').on('change', '[data-widget-toggle]', function () {
            var selected = [];
            $('[data-widget-toggle]:checked').each(function () {
                var value = String($(this).val() || '');
                if (value !== '') {
                    selected.push(value);
                }
            });

            if (!selected.length) {
                selected = getDefaultWidgets();
                syncWidgetToggles(selected);
            }

            widgetSelection = selected;
            saveWidgetPreferences(widgetSelection);
            applyWidgetVisibility(widgetSelection);
        });

        function fetchAnalytics() {
            if (isLoading) {
                return;
            }

            isLoading = true;
            toggleAlert($alert, '', '');
            setLoadingState($loading, $content, true);

            $.ajax({
                url: '../api/desembarques/analytics.php',
                method: 'GET',
                dataType: 'json',
                data: { range: $rangeSelect.val() }
            })
                .done(function (response) {
                    if (response && response.success) {
                        updateRangeSummary($rangeSummary, response.range || {});
                        updateAnalyticsDashboard(response);
                        setLoadingState($loading, $content, false);

                        var sla = response.analytics && response.analytics.sla ? response.analytics.sla : {};
                        var totalRecords = sla && typeof sla.total_records === 'number' ? sla.total_records : 0;

                        if (!totalRecords) {
                            toggleAlert($alert, 'info', translate('analytics.alert.empty', 'No activity registered for the selected range.'));
                        }
                    } else {
                        var message = response && response.message ? response.message : translate('analytics.alert.error', 'Unable to load analytics.');
                        toggleAlert($alert, 'danger', message);
                        setLoadingState($loading, $content, false);
                    }
                })
                .fail(function (jqXHR) {
                    var status = jqXHR ? jqXHR.status : 0;
                    var message;

                    if (status === 401) {
                        message = translate('common.session_expired', 'Your session has expired. Please sign in again.');
                        window.setTimeout(function () {
                            window.location.href = 'login.php?status=expired&lang=' + encodeURIComponent(language);
                        }, 1500);
                    } else if (status === 403) {
                        message = translate('common.authorization_error', 'You do not have permission to view this information.');
                    } else {
                        message = translate('analytics.alert.error', 'Unable to load analytics.');
                    }

                    toggleAlert($alert, status === 401 ? 'warning' : 'danger', message);
                    setLoadingState($loading, $content, false);
                })
                .always(function () {
                    isLoading = false;
                });
        }

        if (defaultRange && $rangeSelect.val() !== String(defaultRange)) {
            $rangeSelect.val(String(defaultRange));
        }

        fetchAnalytics();
    });
})(window.jQuery);
