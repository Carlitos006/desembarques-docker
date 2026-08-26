(function ($) {
    'use strict';

    var reportConfig = window.ReportConfig || {};
    var translations = reportConfig.translations || {};
    var language = reportConfig.language || 'es';
    var userRole = reportConfig.role || '';
    var currentUserId = Number(reportConfig.userId || 0);
    var csrfToken = typeof reportConfig.csrfToken === 'string' ? reportConfig.csrfToken : '';
    var fileUploadConfig = reportConfig.fileUpload || {};
    var maxAttachmentSize = Number(fileUploadConfig.maxSize || 0);
    var maxAttachmentsPerRequest = Number(fileUploadConfig.maxFilesPerRequest || 0);
    var allowedExtensions = Array.isArray(fileUploadConfig.allowedExtensions)
        ? fileUploadConfig.allowedExtensions.map(function (extension) {
            return String(extension || '').toLowerCase();
        })
        : [];
    var allowedExtensionsSet = {};
    var avisoContacts = reportConfig.avisoContacts && typeof reportConfig.avisoContacts === 'object'
        ? reportConfig.avisoContacts
        : {};
    var defaultAvisoFooterLines = [
        'www.grupogerez.com',
        'Saturno No. 100  esq. Calle Luna Col. Anáhuac',
        'C.P. 89180 Tampico, Tam., México.',
        'Tels. (833) 214-0041, 212-5958 Ext. 103'
    ];
    var avisoFooter = typeof reportConfig.avisoFooter === 'string' && reportConfig.avisoFooter.trim() !== ''
        ? reportConfig.avisoFooter
        : defaultAvisoFooterLines.join('\n');
    var avisoWatermarkText = translate(
        'reports.aviso_modal.watermark',
        language === 'en' ? 'Draft' : 'Borrador'
    ).trim();
    var avisoBackgroundUrl = typeof reportConfig.avisoBackgroundUrl === 'string'
        ? reportConfig.avisoBackgroundUrl.trim()
        : '';
    var avisoWatermarkUrl = typeof reportConfig.avisoWatermarkUrl === 'string'
        ? reportConfig.avisoWatermarkUrl.trim()
        : '';
    var avisoTemplateUrl = typeof reportConfig.avisoTemplateUrl === 'string'
        ? reportConfig.avisoTemplateUrl.trim()
        : 'assets/pdf/aviso-desembarque-plantilla.pdf';
    var avisoLoadUrl = typeof reportConfig.avisoLoadUrl === 'string'
        ? reportConfig.avisoLoadUrl.trim()
        : '../api/desembarques/aviso/load.php';
    var avisoSaveUrl = typeof reportConfig.avisoSaveUrl === 'string'
        ? reportConfig.avisoSaveUrl.trim()
        : '../api/desembarques/aviso/save.php';
    var avisoProfilesUrl = typeof reportConfig.avisoProfilesUrl === 'string'
        ? reportConfig.avisoProfilesUrl.trim()
        : '../api/desembarques/aviso/profiles.php';
    var avisoImageUrl = typeof reportConfig.avisoImageUrl === 'string'
        ? reportConfig.avisoImageUrl.trim()
        : '../api/desembarques/aviso/image.php';
    var avisoVersionsUrl = typeof reportConfig.avisoVersionsUrl === 'string'
        ? reportConfig.avisoVersionsUrl.trim()
        : '../api/desembarques/aviso/versions.php';
    var uploadEndpoint = typeof fileUploadConfig.uploadUrl === 'string' ? fileUploadConfig.uploadUrl : '';
    var deleteEndpoint = typeof fileUploadConfig.deleteUrl === 'string' ? fileUploadConfig.deleteUrl : '';
    var avisoLogoPromise = null;
    var avisoBackgroundPromise = null;
    var avisoWatermarkPromise = null;
    var numberFormatter;
    var decimalFormatter;
    var monthDisplayFormatter;
    var analyticsExportEmptyText = translate(
        'reports.export.analytics_empty',
        language === 'en'
            ? 'Analytics are not available for the current selection.'
            : 'No hay suficiente información para exportar la analítica.'
    );

    allowedExtensions.forEach(function (extension) {
        if (extension) {
            allowedExtensionsSet[extension] = true;
        }
    });

    try {
        numberFormatter = new Intl.NumberFormat(language);
    } catch (error) {
        numberFormatter = new Intl.NumberFormat('es');
    }

    try {
        decimalFormatter = new Intl.NumberFormat(language, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    } catch (decimalFormatterError) {
        decimalFormatter = new Intl.NumberFormat('es', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    try {
        monthDisplayFormatter = new Intl.DateTimeFormat(language, {
            month: 'short',
            year: 'numeric'
        });
    } catch (monthFormatterError) {
        monthDisplayFormatter = new Intl.DateTimeFormat('es', {
            month: 'short',
            year: 'numeric'
        });
    }

    function translate(key, fallback, replacements) {
        var value;

        if (Object.prototype.hasOwnProperty.call(translations, key)) {
            value = translations[key];
        } else if (fallback !== undefined && fallback !== null) {
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

    function formatInteger(value) {
        var numericValue = Number(value);

        if (!Number.isFinite(numericValue)) {
            numericValue = 0;
        }

        return numberFormatter.format(numericValue);
    }

    function formatDecimal(value) {
        var numericValue = Number(value);

        if (!Number.isFinite(numericValue)) {
            numericValue = 0;
        }

        return decimalFormatter.format(numericValue);
    }

    function formatFileSize(bytes) {
        var size = Number(bytes);

        if (!Number.isFinite(size) || size <= 0) {
            return '0 B';
        }

        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var unitIndex = 0;

        while (size >= 1024 && unitIndex < units.length - 1) {
            size /= 1024;
            unitIndex += 1;
        }

        var decimals = unitIndex === 0 || size >= 10 ? 0 : 2;
        var formatted = size.toFixed(decimals);

        if (formatted.indexOf('.') !== -1) {
            formatted = formatted.replace(/0+$/, '').replace(/\.$/, '');
        }

        return formatted + ' ' + units[unitIndex];
    }

    function getFileExtension(name) {
        var value = String(name || '').toLowerCase();
        var dotIndex = value.lastIndexOf('.');

        if (dotIndex === -1) {
            return '';
        }

        return value.substring(dotIndex + 1);
    }

    function isExtensionAllowed(extension) {
        if (!extension) {
            return allowedExtensions.length === 0;
        }

        if (allowedExtensions.length === 0) {
            return true;
        }

        return Object.prototype.hasOwnProperty.call(allowedExtensionsSet, extension);
    }

    function parseDate(value) {
        var trimmed = String(value || '').trim();

        if (trimmed === '') {
            return null;
        }

        var timestamp = Date.parse(trimmed);

        if (!Number.isFinite(timestamp)) {
            timestamp = Date.parse(trimmed + 'T00:00:00');
        }

        if (!Number.isFinite(timestamp)) {
            return null;
        }

        return new Date(timestamp);
    }

    function getRecordPrimaryDate(record) {
        if (!record) {
            return null;
        }

        var candidates = [
            record.fecha_desembarque,
            record.fecha_embarque,
            record.created_at
        ];

        for (var index = 0; index < candidates.length; index += 1) {
            var candidate = parseDate(candidates[index]);

            if (candidate) {
                return candidate;
            }
        }

        return null;
    }

    function buildMonthKey(date) {
        if (!(date instanceof Date) || Number.isNaN(date.getTime())) {
            return '';
        }

        var year = String(date.getFullYear());
        var month = String(date.getMonth() + 1);

        if (month.length < 2) {
            month = '0' + month;
        }

        return year + '-' + month;
    }

    function formatMonthLabel(periodKey) {
        var parts = String(periodKey || '').split('-');

        if (parts.length !== 2) {
            return String(periodKey || '');
        }

        var year = Number(parts[0]);
        var monthIndex = Number(parts[1]) - 1;

        if (!Number.isFinite(year) || !Number.isFinite(monthIndex)) {
            return String(periodKey || '');
        }

        var date = new Date(year, monthIndex, 1);

        if (!(date instanceof Date) || Number.isNaN(date.getTime())) {
            return String(periodKey || '');
        }

        try {
            return monthDisplayFormatter.format(date);
        } catch (formatError) {
            return date.getFullYear() + '-' + String(monthIndex + 1).padStart(2, '0');
        }
    }

    function escapeCsvValue(value) {
        var stringValue = value === null || value === undefined ? '' : String(value);
        var needsQuotes = /[",\r\n]/.test(stringValue);

        if (stringValue.indexOf('"') !== -1) {
            stringValue = stringValue.replace(/"/g, '""');
        }

        if (needsQuotes) {
            stringValue = '"' + stringValue + '"';
        }

        return stringValue;
    }

    var clientColorOverrides = {
        woodside: '#d71538',
        transocean: '#F8BD00',
        sbm: '#ff6c1d',
        hornbeck: '#0d3159'
    };

    function generateChartColors(count) {
        var basePalette = [
            '#0d6efd',
            '#6610f2',
            '#6f42c1',
            '#d63384',
            '#dc3545',
            '#fd7e14',
            '#ffc107',
            '#198754',
            '#20c997',
            '#0dcaf0',
            '#6c757d'
        ];
        var colors = [];
        var paletteLength = basePalette.length;

        for (var index = 0; index < count; index += 1) {
            if (index < paletteLength) {
                colors.push(basePalette[index]);
            } else {
                var hue = Math.round((index * 137.508) % 360);
                colors.push('hsl(' + hue + ', 65%, 55%)');
            }
        }

        return colors;
    }

    function resolveClientColorOverride(label) {
        var normalizedLabel = String(label || '').toLowerCase();
        var matchedKey = null;

        Object.keys(clientColorOverrides).some(function (key) {
            if (normalizedLabel.indexOf(key) !== -1) {
                matchedKey = key;

                return true;
            }

            return false;
        });

        if (matchedKey) {
            return clientColorOverrides[matchedKey];
        }

        return null;
    }

    function formatRelativeTime(timestamp, fallback) {
        if (!timestamp) {
            return fallback || '';
        }

        var date = new Date(timestamp);

        if (Number.isNaN(date.getTime())) {
            return fallback || '';
        }

        var now = Date.now();
        var diffSeconds = Math.round((date.getTime() - now) / 1000);
        var absDiff = Math.abs(diffSeconds);
        var units = [
            { unit: 'year', seconds: 60 * 60 * 24 * 365 },
            { unit: 'month', seconds: 60 * 60 * 24 * 30 },
            { unit: 'day', seconds: 60 * 60 * 24 },
            { unit: 'hour', seconds: 60 * 60 },
            { unit: 'minute', seconds: 60 },
            { unit: 'second', seconds: 1 }
        ];
        var value = 0;
        var unit = 'second';

        for (var index = 0; index < units.length; index += 1) {
            var threshold = units[index].seconds;

            if (absDiff >= threshold || units[index].unit === 'second') {
                value = Math.round(diffSeconds / threshold);
                unit = units[index].unit;

                break;
            }
        }

        try {
            var formatter = new Intl.RelativeTimeFormat(language || 'es', { numeric: 'auto' });

            return formatter.format(value, unit);
        } catch (error) {
            return fallback || '';
        }
    }

    function renderStatusDashboard(statusSummary, options) {
        if (!options || !options.list || !options.empty) {
            return;
        }

        var $list = options.list;
        var $empty = options.empty;

        $list.empty();

        if (!Array.isArray(statusSummary) || statusSummary.length === 0) {
            if ($empty.length) {
                $empty.removeClass('d-none');
            }

            return;
        }

        if ($empty.length) {
            $empty.addClass('d-none');
        }

        var total = 0;

        statusSummary.forEach(function (entry) {
            total += Number(entry.count || 0);
        });

        if (total <= 0) {
            if ($empty.length) {
                $empty.removeClass('d-none');
            }

            return;
        }

        var countLabel = translate('reports.dashboard.count_label', language === 'en' ? 'Records' : 'Registros');
        var averageLabel = translate('reports.dashboard.average_label', language === 'en' ? 'Average' : 'Promedio');
        var daysSuffix = translate('reports.dashboard.days_suffix', language === 'en' ? 'days' : 'días');
        var colors = ['bg-primary', 'bg-success', 'bg-info', 'bg-warning', 'bg-secondary', 'bg-dark'];

        var fallbackStatusLabel = translate(
            'reports.table.not_available',
            language === 'en' ? 'Not available' : 'No disponible'
        );

        var html = statusSummary.map(function (entry, index) {
            var count = Number(entry.count || 0);
            var label = entry.label ? String(entry.label) : fallbackStatusLabel;
            var percentage = total > 0 ? Math.round((count / total) * 100) : 0;
            var average = Number(entry.average_dias_transcurridos || 0);

            if (!Number.isFinite(average)) {
                average = 0;
            }

            var badgeClass = colors[index % colors.length];
            var progressWidth = Math.min(100, Math.max(0, percentage));

            return [
                '<div class="border rounded-3 p-3">',
                    '<div class="d-flex justify-content-between align-items-start gap-3">',
                        '<div>',
                            '<p class="fw-semibold mb-0">', escapeHtml(label), '</p>',
                            '<p class="text-muted small mb-0">', escapeHtml(countLabel), '</p>',
                        '</div>',
                        '<div class="text-end">',
                            '<div class="fs-5 fw-semibold">', formatInteger(count), '</div>',
                            '<div class="text-muted small">', escapeHtml(averageLabel), ': ', formatInteger(Math.round(average)), ' ', escapeHtml(daysSuffix), '</div>',
                        '</div>',
                    '</div>',
                    '<div class="progress mt-2" style="height: 6px;">',
                        '<div class="progress-bar ', badgeClass, '" role="progressbar" style="width: ', progressWidth, '%;" aria-valuenow="', progressWidth, '" aria-valuemin="0" aria-valuemax="100"></div>',
                    '</div>',
                    '<p class="text-muted small mb-0 mt-2">', formatInteger(percentage), '%</p>',
                '</div>'
            ].join('');
        }).join('');

        $list.html(html);
    }

    function renderClientsDashboard(clientsSummary, options) {
        if (!options || !options.list || !options.empty) {
            return;
        }

        var $list = options.list;
        var $empty = options.empty;

        $list.empty();

        if (!Array.isArray(clientsSummary) || clientsSummary.length === 0) {
            if ($empty.length) {
                $empty.removeClass('d-none');
            }

            return;
        }

        if ($empty.length) {
            $empty.addClass('d-none');
        }

        var countLabel = translate('reports.dashboard.count_label', language === 'en' ? 'Records' : 'Registros');

        var html = clientsSummary.map(function (client) {
            var display = client.display ? String(client.display) : translate('reports.dashboard.unknown_client', language === 'en' ? 'Unassigned' : 'Sin cliente asignado');
            var email = client.email ? String(client.email) : '';
            var count = Number(client.count || 0);
            var percentage = Number(client.percentage || 0);
            var subtitle = email !== '' ? '<div class="text-muted small">' + escapeHtml(email) + '</div>' : '';

            return [
                '<li class="list-group-item d-flex justify-content-between align-items-start gap-3">',
                    '<div>',
                        '<div class="fw-semibold">', escapeHtml(display), '</div>',
                        subtitle,
                    '</div>',
                    '<div class="text-end">',
                        '<div class="fw-semibold">', formatInteger(count), '</div>',
                        '<div class="text-muted small">', escapeHtml(countLabel), '</div>',
                        '<div class="text-muted small">', formatInteger(Math.round(percentage)), '%</div>',
                    '</div>',
                '</li>'
            ].join('');
        }).join('');

        $list.html(html);
    }

    function renderPeriodDashboard(periodSummary, options) {
        if (!options || !options.list || !options.empty) {
            return;
        }

        var $list = options.list;
        var $empty = options.empty;

        $list.empty();

        if (!Array.isArray(periodSummary) || periodSummary.length === 0) {
            if ($empty.length) {
                $empty.removeClass('d-none');
            }

            return;
        }

        if ($empty.length) {
            $empty.addClass('d-none');
        }

        var countLabel = translate('reports.dashboard.count_label', language === 'en' ? 'Records' : 'Registros');
        var averageLabel = translate('reports.dashboard.average_label', language === 'en' ? 'Average' : 'Promedio');
        var daysSuffix = translate('reports.dashboard.days_suffix', language === 'en' ? 'days' : 'días');
        var maxCount = 0;

        periodSummary.forEach(function (period) {
            var count = Number(period.count || 0);

            if (count > maxCount) {
                maxCount = count;
            }
        });

        if (maxCount <= 0) {
            maxCount = 1;
        }

        var html = periodSummary.map(function (period) {
            var label = period.label ? String(period.label) : String(period.period || '');
            var count = Number(period.count || 0);
            var average = Number(period.average_dias_transcurridos || 0);

            if (!Number.isFinite(average)) {
                average = 0;
            }

            var progressWidth = Math.round((count / maxCount) * 100);

            return [
                '<div class="border rounded-3 p-3">',
                    '<div class="d-flex justify-content-between align-items-start gap-3">',
                        '<div>',
                            '<p class="fw-semibold mb-0">', escapeHtml(label), '</p>',
                            '<p class="text-muted small mb-0">', escapeHtml(countLabel), '</p>',
                        '</div>',
                        '<div class="text-end">',
                            '<div class="fw-semibold">', formatInteger(count), '</div>',
                            '<div class="text-muted small">', escapeHtml(averageLabel), ': ', formatInteger(Math.round(average)), ' ', escapeHtml(daysSuffix), '</div>',
                        '</div>',
                    '</div>',
                    '<div class="progress mt-2" style="height: 6px;">',
                        '<div class="progress-bar bg-secondary" role="progressbar" style="width: ', Math.min(progressWidth, 100), '%;" aria-valuenow="', Math.min(progressWidth, 100), '" aria-valuemin="0" aria-valuemax="100"></div>',
                    '</div>',
                '</div>'
            ].join('');
        }).join('');

        $list.html(html);
    }

    function renderRiskDashboard(riskSummary, options) {
        if (!options || !options.list || !options.empty) {
            return;
        }

        var $list = options.list;
        var $empty = options.empty;

        $list.empty();

        if (!Array.isArray(riskSummary) || riskSummary.length === 0) {
            if ($empty.length) {
                $empty.removeClass('d-none');
            }

            return;
        }

        if ($empty.length) {
            $empty.addClass('d-none');
        }

        var daysSuffix = translate('reports.dashboard.days_suffix', language === 'en' ? 'days' : 'días');

        var html = riskSummary.map(function (record) {
            var reference = record.reference ? String(record.reference) : ('#' + (record.id || ''));
            var client = record.client ? '<div class="text-muted small">' + escapeHtml(String(record.client)) + '</div>' : '';
            var status = record.status_label ? '<div class="text-muted small">' + escapeHtml(String(record.status_label)) + '</div>' : '';
            var riskLevel = String(record.risk_level || '');
            var badgeClass = riskLevel === 'breach' ? 'text-bg-danger' : 'text-bg-warning';
            var dias = Number(record.dias_transcurridos || 0);

            return [
                '<li class="list-group-item d-flex justify-content-between align-items-start gap-3">',
                    '<div>',
                        '<div class="fw-semibold">', escapeHtml(reference), '</div>',
                        client,
                    '</div>',
                    '<div class="text-end">',
                        '<span class="badge ', badgeClass, '">', formatInteger(dias), ' ', escapeHtml(daysSuffix), '</span>',
                        status,
                    '</div>',
                '</li>'
            ].join('');
        }).join('');

        $list.html(html);
    }

    function renderRecentDashboard(recentChanges, options) {
        if (!options || !options.body) {
            return;
        }

        var $body = options.body;
        var emptyMessage = options.emptyMessage;

        $body.empty();

        if (!Array.isArray(recentChanges) || recentChanges.length === 0) {
            var emptyText = emptyMessage
                || translate('reports.dashboard.recent_empty', language === 'en' ? 'No recent activity.' : 'No hay cambios recientes.');

            $body.append('<tr data-dashboard-empty-row><td colspan="3" class="text-center text-muted small py-3">' + escapeHtml(emptyText) + '</td></tr>');

            return;
        }

        var pendingLabel = translate('reports.dashboard.pending_review', language === 'en' ? 'Pending review' : 'Pendiente de revisión');

        var rowsHtml = recentChanges.map(function (change) {
            var reference = change.reference ? String(change.reference) : ('#' + (change.record_id || ''));
            var client = change.client ? '<div class="text-muted small">' + escapeHtml(String(change.client)) + '</div>' : '';
            var summary = change.summary ? escapeHtml(String(change.summary)) : '';
            var fields = Array.isArray(change.fields) && change.fields.length
                ? '<div class="text-muted small">' + escapeHtml(change.fields.join(', ')) + '</div>'
                : '';
            var userDisplay = change.user && change.user.display ? '<div class="text-muted small">' + escapeHtml(String(change.user.display)) + '</div>' : '';
            var status = change.status_label ? '<div class="text-muted small">' + escapeHtml(String(change.status_label)) + '</div>' : '';
            var badge = change.pending_review
                ? '<span class="badge text-bg-warning ms-2">' + escapeHtml(pendingLabel) + '</span>'
                : '';
            var timestampDisplay = change.timestamp_display ? String(change.timestamp_display) : '';
            var timestampRelative = formatRelativeTime(change.timestamp, timestampDisplay);
            var whenContent = escapeHtml(timestampRelative || timestampDisplay);
            var absolute = '';

            if (timestampDisplay && timestampDisplay !== whenContent) {
                absolute = '<div class="text-muted small">' + escapeHtml(timestampDisplay) + '</div>';
            }

            return [
                '<tr>',
                    '<td>',
                        '<div class="fw-semibold">', escapeHtml(reference), '</div>',
                        client,
                    '</td>',
                    '<td>',
                        '<div>', summary, badge, '</div>',
                        status,
                        fields,
                        userDisplay,
                    '</td>',
                    '<td class="text-end text-nowrap">',
                        whenContent,
                        absolute,
                    '</td>',
                '</tr>'
            ].join('');
        }).join('');

        $body.html(rowsHtml);
    }

    function updateSlaDashboard(slaData, options) {
        if (!options) {
            return;
        }

        var $warning = options.warning;
        var $breach = options.breach;
        var warningCount = slaData && typeof slaData.warning_count !== 'undefined'
            ? Number(slaData.warning_count)
            : 0;
        var breachCount = slaData && typeof slaData.breach_count !== 'undefined'
            ? Number(slaData.breach_count)
            : 0;
        var warningThreshold = slaData && typeof slaData.warning_threshold_hours !== 'undefined'
            ? Number(slaData.warning_threshold_hours)
            : 0;
        var breachThreshold = slaData && typeof slaData.breach_threshold_hours !== 'undefined'
            ? Number(slaData.breach_threshold_hours)
            : 0;

        if ($warning && $warning.length) {
            if (warningCount > 0) {
                $warning
                    .text(
                        translate(
                            'reports.dashboard.sla_warning',
                            '{{count}} in monitoring (≥ {{hours}} h)',
                            {
                                count: formatInteger(warningCount),
                                hours: formatInteger(Math.round(warningThreshold))
                            }
                        )
                    )
                    .removeClass('d-none');
            } else {
                $warning.addClass('d-none').text('');
            }
        }

        if ($breach && $breach.length) {
            if (breachCount > 0) {
                $breach
                    .text(
                        translate(
                            'reports.dashboard.sla_breach',
                            '{{count}} out of SLA (≥ {{hours}} h)',
                            {
                                count: formatInteger(breachCount),
                                hours: formatInteger(Math.round(breachThreshold))
                            }
                        )
                    )
                    .removeClass('d-none');
            } else {
                $breach.addClass('d-none').text('');
            }
        }
    }

    function updateDashboard(dashboard, options) {
        if (!options || !options.section || !options.section.length) {
            return;
        }

        var data = dashboard || {};

        renderStatusDashboard(Array.isArray(data.status) ? data.status : [], {
            list: options.statusList,
            empty: options.statusEmpty
        });

        renderClientsDashboard(Array.isArray(data.clients) ? data.clients : [], {
            list: options.clientsList,
            empty: options.clientsEmpty
        });

        renderPeriodDashboard(Array.isArray(data.periods) ? data.periods : [], {
            list: options.periodList,
            empty: options.periodEmpty
        });

        renderRiskDashboard(
            data.sla && Array.isArray(data.sla.at_risk) ? data.sla.at_risk : [],
            {
                list: options.riskList,
                empty: options.riskEmpty
            }
        );

        renderRecentDashboard(Array.isArray(data.recent_changes) ? data.recent_changes : [], {
            body: options.recentBody,
            emptyMessage: options.recentEmptyMessage
        });

        updateSlaDashboard(data.sla || {}, {
            warning: options.slaWarning,
            breach: options.slaBreach
        });
    }

    $(function () {
        var $filtersForm = $('#report-filters');
        var $resetButton = $('#report-filters-reset');
        var $alert = $('#report-alert');
        var $table = $('#report-results');
        var $tableBody = $table.find('tbody');
        var columnCount = $table.find('thead th').length || 1;
        var $canceledSection = $('#report-canceled-section');
        var $canceledTable = $('#report-canceled-results');
        var $canceledTableBody = $canceledTable.find('tbody');
        var canceledColumnCount = $canceledTable.find('thead th').length || 1;
        var $summaryCount = $('[data-summary="count"]');
        var $summaryDiasTranscurridos = $('[data-summary="dias_transcurridos"]');
        var $summaryDiasFuera = $('[data-summary="dias_fuera"]');
        var $dashboardSection = $('#report-dashboard');
        var $dashboardStatusList = $dashboardSection.find('[data-dashboard="status-list"]');
        var $dashboardStatusEmpty = $dashboardSection.find('[data-dashboard-empty="status"]');
        var $dashboardClientsList = $dashboardSection.find('[data-dashboard="clients-list"]');
        var $dashboardClientsEmpty = $dashboardSection.find('[data-dashboard-empty="clients"]');
        var $dashboardPeriodList = $dashboardSection.find('[data-dashboard="period-list"]');
        var $dashboardPeriodEmpty = $dashboardSection.find('[data-dashboard-empty="periods"]');
        var $dashboardRiskList = $dashboardSection.find('[data-dashboard="risk-list"]');
        var $dashboardRiskEmpty = $dashboardSection.find('[data-dashboard-empty="risk"]');
        var $dashboardRecentBody = $dashboardSection.find('[data-dashboard="recent-body"]');
        var $dashboardRecentEmptyCell = $dashboardSection.find('[data-dashboard-empty="recent"]');
        var recentEmptyMessage = $dashboardRecentEmptyCell.length
            ? $dashboardRecentEmptyCell.text()
            : '';
        var $dashboardSlaWarning = $dashboardSection.find('[data-dashboard="sla-warning"]');
        var $dashboardSlaBreach = $dashboardSection.find('[data-dashboard="sla-breach"]');

        if (!recentEmptyMessage) {
            recentEmptyMessage = translate(
                'reports.dashboard.recent_empty',
                language === 'en' ? 'No recent activity.' : 'No hay cambios recientes.'
            );
        }

        var dashboardOptions = {
            section: $dashboardSection,
            statusList: $dashboardStatusList,
            statusEmpty: $dashboardStatusEmpty,
            clientsList: $dashboardClientsList,
            clientsEmpty: $dashboardClientsEmpty,
            periodList: $dashboardPeriodList,
            periodEmpty: $dashboardPeriodEmpty,
            riskList: $dashboardRiskList,
            riskEmpty: $dashboardRiskEmpty,
            recentBody: $dashboardRecentBody,
            recentEmptyMessage: recentEmptyMessage,
            slaWarning: $dashboardSlaWarning,
            slaBreach: $dashboardSlaBreach
        };

        if ($dashboardSection.length) {
            updateDashboard({}, dashboardOptions);
        }

        var $editAttachmentsInput = $('#report-edit-attachments');
        var $editAttachmentsUploadButton = $('#report-edit-attachments-upload');
        var $editAttachmentsList = $('#report-edit-attachments-list');
        var $editAttachmentsEmpty = $('#report-edit-attachments-empty');
        var $editAttachmentsFeedback = $('#report-edit-attachments-feedback');
        var $avisoForm = $('#report-aviso-form');
        var $avisoAlert = $('#report-aviso-alert');
        var $avisoRecordIdInput = $('#report-aviso-record-id');
        var $avisoExcelInput = $('#report-aviso-excel');
        var $avisoProfileSelect = $('#report-aviso-profile');
        var $avisoSaveProfileButton = $('#report-aviso-save-profile');
        var $avisoReadiness = $('#report-aviso-readiness');
        var $avisoReadinessText = $('[data-aviso-readiness-text]');
        var $avisoExcelStatus = $('#report-aviso-excel-status');
        var $avisoExcelMeta = $('#report-aviso-excel-meta');
        var $avisoExcelPreview = $('#report-aviso-excel-preview');
        var $avisoImportersWrap = $('#report-aviso-importers-wrap');
        var $avisoImporters = $('#report-aviso-importers');
        var $avisoPhotoFilesInput = $('#report-aviso-photo-files');
        var $avisoPhotoUploadButton = $('#report-aviso-photo-upload');
        var $avisoPhotoFeedback = $('#report-aviso-photo-feedback');
        var $avisoPhotosCount = $('#report-aviso-photos-count');
        var $avisoPhotosEmpty = $('#report-aviso-photos-empty');
        var $avisoPhotosSelected = $('#report-aviso-photos-selected');
        var $avisoPhotosAvailableWrap = $('#report-aviso-photos-available-wrap');
        var $avisoPhotosAvailable = $('#report-aviso-photos-available');
        var $avisoVersionsCount = $('#report-aviso-versions-count');
        var $avisoVersionsStatus = $('#report-aviso-versions-status');
        var $avisoVersionsEmpty = $('#report-aviso-versions-empty');
        var $avisoVersionsList = $('#report-aviso-versions-list');
        var $avisoDocumentCodeInput = $('#report-aviso-document-code');
        var $avisoRigNameInput = $('#report-aviso-rig-name');
        var $avisoRigImoInput = $('#report-aviso-rig-imo');
        var $avisoRigFieldInput = $('#report-aviso-rig-field');
        var $avisoRigAreaInput = $('#report-aviso-rig-area');
        var $avisoComitenteInput = $('#report-aviso-comitente');
        var $avisoDocumentTitleInput = $('#report-aviso-document-title');
        var $avisoNoticeNumberInput = $('#report-aviso-notice-number');
        var $avisoLocationDateInput = $('#report-aviso-location-date');
        var $avisoRecipientInput = $('#report-aviso-recipient');
        var $avisoIntroductionInput = $('#report-aviso-introduction');
        var $avisoBodyInput = $('#report-aviso-body');
        var $avisoOperationsInput = $('#report-aviso-operations');
        var $avisoItemsInput = $('#report-aviso-items');
        var $avisoDocumentationInput = $('#report-aviso-documentation');
        var $avisoClosingInput = $('#report-aviso-closing');
        var $avisoSignerNameInput = $('#report-aviso-signer-name');
        var $avisoSignerTitleInput = $('#report-aviso-signer-title');
        var $avisoFooterInput = $('#report-aviso-footer');
        var $avisoSummaryReference = $('[data-summary="reference"]');
        var $avisoSummaryVessel = $('[data-summary="vessel"]');
        var $avisoSummaryDestination = $('[data-summary="destination"]');
        var $avisoSummaryDates = $('[data-summary="dates"]');
        var pedimentoDetailsModalElement = document.getElementById('pedimento-details-modal');
        var pedimentoDetailsModal = null;
        var $pedimentoDetailsModalTitle = $('#pedimento-details-modal-label');
        var $pedimentoDetailsList = $('#pedimento-details-list');
        var $pedimentoDetailsEmpty = $('#pedimento-details-empty');
        var pedimentoDetailsModalTitles = { pedimentos: '', partidas: '' };
        var pedimentoDetailsModalEmptyTexts = { pedimentos: '', partidas: '' };
        var pedimentoDetailsButtonLabel = translate(
            'reports.table.view_details',
            language === 'en' ? 'View details' : 'Ver detalles'
        );
        var attachmentsTitleText = translate(
            'reports.attachments.title',
            language === 'en' ? 'Attachments' : 'Archivos adjuntos'
        );
        var attachmentsModalElement = document.getElementById('report-attachments-modal');
        var attachmentsModal = null;
        var $attachmentsModalTitle = $('#report-attachments-modal-label');
        var $attachmentsModalList = $('#report-attachments-list');
        var $attachmentsModalEmpty = $('#report-attachments-empty');
        var attachmentsModalBaseTitle = attachmentsTitleText;

        if (pedimentoDetailsButtonLabel === 'reports.table.view_details') {
            pedimentoDetailsButtonLabel = language === 'en' ? 'View details' : 'Ver detalles';
        }

        if (attachmentsModalElement && typeof window.bootstrap !== 'undefined' && window.bootstrap && typeof window.bootstrap.Modal !== 'undefined') {
            try {
                if (typeof window.bootstrap.Modal.getOrCreateInstance === 'function') {
                    attachmentsModal = window.bootstrap.Modal.getOrCreateInstance(attachmentsModalElement);
                } else {
                    attachmentsModal = new window.bootstrap.Modal(attachmentsModalElement);
                }
            } catch (error) {
                attachmentsModal = null;
            }
        }

        if (attachmentsModalElement) {
            attachmentsModalElement.addEventListener('hidden.bs.modal', function () {
                if ($attachmentsModalTitle.length) {
                    $attachmentsModalTitle.text(attachmentsModalBaseTitle);
                }

                if ($attachmentsModalList.length) {
                    $attachmentsModalList.empty().addClass('d-none').attr('aria-busy', 'false');
                }

                if ($attachmentsModalEmpty.length) {
                    $attachmentsModalEmpty.removeClass('d-none');
                }
            });
        }

        if (pedimentoDetailsModalElement) {
            pedimentoDetailsModalTitles.pedimentos = pedimentoDetailsModalElement.getAttribute('data-pedimentos-title') || '';
            pedimentoDetailsModalTitles.partidas = pedimentoDetailsModalElement.getAttribute('data-partidas-title') || '';
            pedimentoDetailsModalEmptyTexts.pedimentos = pedimentoDetailsModalElement.getAttribute('data-pedimentos-empty') || '';
            pedimentoDetailsModalEmptyTexts.partidas = pedimentoDetailsModalElement.getAttribute('data-partidas-empty') || '';

            if (typeof window.bootstrap !== 'undefined' && window.bootstrap && typeof window.bootstrap.Modal !== 'undefined') {
                try {
                    if (typeof window.bootstrap.Modal.getOrCreateInstance === 'function') {
                        pedimentoDetailsModal = window.bootstrap.Modal.getOrCreateInstance(pedimentoDetailsModalElement);
                    } else {
                        pedimentoDetailsModal = new window.bootstrap.Modal(pedimentoDetailsModalElement);
                    }
                } catch (error) {
                    pedimentoDetailsModal = null;
                }
            }
        }

        if (!pedimentoDetailsModalTitles.pedimentos) {
            pedimentoDetailsModalTitles.pedimentos = translate(
                'reports.modal.pedimentos.title',
                language === 'en' ? 'Customs entries for the unloading record' : 'Pedimentos del desembarque'
            );
        }

        if (!pedimentoDetailsModalTitles.partidas) {
            pedimentoDetailsModalTitles.partidas = translate(
                'reports.modal.partidas.title',
                language === 'en' ? 'Entry line items' : 'Partidas del pedimento'
            );
        }

        if (!pedimentoDetailsModalEmptyTexts.pedimentos) {
            pedimentoDetailsModalEmptyTexts.pedimentos = translate(
                'reports.modal.pedimentos.empty',
                language === 'en' ? 'No customs entries are available.' : 'No hay pedimentos registrados.'
            );
        }

        if (!pedimentoDetailsModalEmptyTexts.partidas) {
            pedimentoDetailsModalEmptyTexts.partidas = translate(
                'reports.modal.partidas.empty',
                language === 'en' ? 'No line items are available.' : 'No hay partidas registradas.'
            );
        }

        var isLoading = false;
        var notAvailableText = translate('reports.table.not_available', '—');
        var tableEmptyText = translate('reports.table.empty', 'No records were found for the selected filters.');
        var activeTableEmptyText = translate(
            'reports.table.active_empty',
            'No active records found. Review the canceled records below.'
        );
        var canceledTableEmptyText = translate(
            'reports.table.canceled.empty',
            'No canceled records were found for the selected filters.'
        );
        var tableLoadingText = translate('reports.table.loading', 'Loading information...');
        var $exportPdfButton = $('#reports-export-pdf');
        var $exportExcelButton = $('#reports-export-excel');
        var $exportAnalyticsButton = $('#reports-export-analytics');
        var currentRecords = [];
        var observacionesState = {};
        var lastUnreadRecordIds = {};
        var hasFetchedInitialReports = false;
        var unreadObservacionesIntervalId = null;
        var unreadObservacionesImmediateTimeoutId = null;
        var isCheckingUnreadObservaciones = false;
        var unreadObservacionesPollDelay = 15000;
        var unreadObservacionesImmediateCheckDelay = 5000;
        var pendingFetchAfterLoad = false;
        var unreadAlertOptions = { force: true };
        var unreadObservacionesStream = null;
        var unreadObservacionesStreamHealthy = false;
        var unreadObservacionesStreamReconnectTimeoutId = null;
        var unreadObservacionesStreamRefreshTimeoutId = null;
        var unreadObservacionesStreamLastEventAt = 0;
        var unreadObservacionesStreamSupports = typeof window !== 'undefined'
            && typeof window.EventSource === 'function';
        var unreadObservacionesStreamRetryDelay = 5000;
        var chartRecordsByClientElement = document.getElementById('chart-records-by-client');
        var chartDaysDistributionElement = document.getElementById('chart-days-distribution');
        var chartRecordsByStatusElement = document.getElementById('chart-records-by-status');
        var chartRecordsByVesselElement = document.getElementById('chart-records-by-vessel');
        var chartRecordsTrendElement = document.getElementById('chart-records-trend');
        var chartAverageByStatusElement = document.getElementById('chart-average-by-status');
        var chartClientPerformanceElement = document.getElementById('chart-client-performance');
        var $chartEmptyRecordsByClient = $('[data-chart-empty="records-by-client"]');
        var $chartEmptyDaysDistribution = $('[data-chart-empty="days-distribution"]');
        var $chartEmptyRecordsByStatus = $('[data-chart-empty="records-by-status"]');
        var $chartEmptyRecordsByVessel = $('[data-chart-empty="records-by-vessel"]');
        var $chartEmptyRecordsTrend = $('[data-chart-empty="records-trend"]');
        var $chartEmptyAverageByStatus = $('[data-chart-empty="average-by-status"]');
        var $chartEmptyClientPerformance = $('[data-chart-empty="client-performance"]');
        var chartsEnabled = userRole === 'admin' || userRole === 'usuario';
        var chartLibraryLoaded = typeof window.Chart === 'function';
        var recordsByClientChart = null;
        var daysDistributionChart = null;
        var recordsByStatusChart = null;
        var recordsByVesselChart = null;
        var recordsTrendChart = null;
        var averageByStatusChart = null;
        var clientPerformanceChart = null;
        var analyticsSnapshot = {
            trend: [],
            statusAverages: [],
            clientPerformance: [],
            statusTotals: [],
            vesselTotals: []
        };

        function resetAnalyticsSnapshot() {
            analyticsSnapshot.trend = [];
            analyticsSnapshot.statusAverages = [];
            analyticsSnapshot.clientPerformance = [];
            analyticsSnapshot.statusTotals = [];
            analyticsSnapshot.vesselTotals = [];
        }
        var exportColumns = [
            {
                key: 'referencia',
                label: translate('reports.table.headers.reference', 'Reference'),
                getValue: function (record) {
                    return record && record.referencia ? record.referencia : '';
                }
            },
            {
                key: 'client',
                label: translate('reports.table.headers.client', 'Client'),
                getValue: function (record) {
                    if (!record) {
                        return notAvailableText;
                    }

                    var primary = record.client_name || record.cliente || '';
                    var secondary = record.client_email || '';
                    var display = buildDisplayValue(primary, secondary);

                    if (!display) {
                        display = record.cliente || '';
                    }

                    return display || notAvailableText;
                }
            },
            {
                key: 'aviso_origin',
                label: language === 'en' ? 'Notice origin' : 'Origen del aviso',
                getValue: function (record) {
                    if (!record) {
                        return '';
                    }

                    return String(record.aviso_origin || '').toLowerCase() === 'historical'
                        ? (language === 'en' ? 'Historical' : 'Histórico')
                        : (language === 'en' ? 'System' : 'Sistema');
                }
            },
            {
                key: 'barco',
                label: translate('reports.table.headers.vessel', 'Vessel'),
                getValue: function (record) {
                    return record && record.barco ? record.barco : '';
                }
            },
            {
                key: 'destino',
                label: translate('reports.table.headers.destination', 'Destination'),
                getValue: function (record) {
                    return record && record.destino ? record.destino : '';
                }
            },
            {
                key: 'status_label',
                label: translate('reports.table.headers.status', 'Status'),
                getValue: function (record) {
                    if (!record) {
                        return notAvailableText;
                    }

                    var label = record.status_label || '';

                    if (!label && record.status_slug) {
                        label = record.status_slug;
                    }

                    return label || notAvailableText;
                }
            },
            {
                key: 'descripcion',
                label: translate('reports.table.headers.description', 'Description'),
                getValue: function (record) {
                    if (!record || !record.descripcion) {
                        return notAvailableText;
                    }

                    return record.descripcion;
                }
            },
            {
                key: 'pedimento_header_numbers',
                label: translate('reports.table.headers.pedimentos', 'Customs entry numbers'),
                getValue: function (record) {
                    if (!record) {
                        return '';
                    }

                    var values = normalizeReferenceList(record.pedimento_header_numbers);

                    return values.join(', ');
                }
            },
            {
                key: 'pedimento_header_references',
                label: translate('reports.table.headers.partidas', 'Line items'),
                getValue: function (record) {
                    if (!record) {
                        return '';
                    }

                    var values = normalizeReferenceList(record.pedimento_header_references);

                    return values.join(', ');
                }
            },
            {
                key: 'observaciones_count',
                label: translate('reports.table.headers.observaciones', 'Observations'),
                getValue: function (record) {
                    if (!record) {
                        return notAvailableText;
                    }

                    var count = normalizeObservationCount(record.observaciones_count);

                    if (count === 0) {
                        return notAvailableText;
                    }

                    var formattedCount = formatInteger(count);

                    if (observationsCountFormat.indexOf('{{count}}') !== -1) {
                        return observationsCountFormat.replace(/\{\{\s*count\s*\}\}/g, formattedCount);
                    }

                    return observationsCountFormat + ' ' + formattedCount;
                }
            },
            {
                key: 'attachments',
                label: translate('reports.table.headers.attachments', 'Attachments'),
                getValue: function (record) {
                    if (!record || !Array.isArray(record.attachments) || record.attachments.length === 0) {
                        return '';
                    }

                    var values = record.attachments.map(function (attachment, index) {
                        if (!attachment) {
                            return '';
                        }

                        var name = attachment.original_name || '';

                        if (!name) {
                            name = (language === 'en' ? 'File ' : 'Archivo ') + (index + 1);
                        }

                        if (attachment.size && attachment.size > 0) {
                            name += ' (' + formatFileSize(attachment.size) + ')';
                        }

                        return name;
                    }).filter(function (value) {
                        return value !== '';
                    });

                    return values.join('\n');
                }
            },
            {
                key: 'folio_aviso',
                label: translate('reports.table.headers.notice_number', 'Notice number'),
                getValue: function (record) {
                    return record && record.folio_aviso ? record.folio_aviso : '';
                }
            },
            {
                key: 'fecha_desembarque',
                label: translate('reports.table.headers.landing_date', 'Unloading date'),
                getValue: function (record) {
                    if (!record) {
                        return '';
                    }

                    return record.fecha_desembarque_display || record.fecha_desembarque || '';
                }
            },
            {
                key: 'fecha_embarque',
                label: translate('reports.table.headers.departure_date', 'Departure date'),
                getValue: function (record) {
                    if (!record) {
                        return '';
                    }

                    return record.fecha_embarque_display || record.fecha_embarque || '';
                }
            },
            {
                key: 'dias_transcurridos',
                label: translate('reports.table.headers.days_elapsed', 'Days elapsed'),
                isNumeric: true,
                getValue: function (record) {
                    if (!record) {
                        return 0;
                    }

                    var value = Number(record.dias_transcurridos);
                    return Number.isFinite(value) ? value : 0;
                }
            },
            {
                key: 'dias_fuera',
                label: translate('reports.table.headers.days_out', 'Dwell time (days)'),
                isNumeric: true,
                getValue: function (record) {
                    if (!record) {
                        return 0;
                    }

                    var value = Number(record.dias_fuera);
                    return Number.isFinite(value) ? value : 0;
                }
            }
        ];
        var canEditRecords = Boolean(reportConfig.canEdit);
        var canAddObservaciones = Boolean(reportConfig.canAddObservaciones);

        var pendingEditAttachments = [];
        var isUploadingAttachments = false;

        if (!canEditRecords && (userRole === 'admin' || userRole === 'usuario')) {
            canEditRecords = true;
        }

        if (!canAddObservaciones && (userRole === 'admin' || userRole === 'usuario' || userRole === 'cliente')) {
            canAddObservaciones = true;
        }

        var recordsById = {};
        var avisoDataByRecord = {};
        var avisoProfilesByRecord = {};
        var avisoVersionsByRecord = {};
        var avisoProfilesBusy = false;
        var avisoExcelBusy = false;
        var avisoPhotoUploadBusy = false;
        var pendingSuccessMessage = null;
        var updateCompletedMessage = 'Update completed';
        var editModalElement = document.getElementById('report-edit-modal');
        var editModal = null;
        var observacionesModalElement = document.getElementById('report-observaciones-modal');
        var observacionesModal = null;
        var avisoModalElement = document.getElementById('report-aviso-modal');
        var avisoModal = null;

        if (editModalElement && window.bootstrap && typeof window.bootstrap.Modal === 'function') {
            editModal = new window.bootstrap.Modal(editModalElement);
        }

        if (observacionesModalElement && window.bootstrap && typeof window.bootstrap.Modal === 'function') {
            observacionesModal = new window.bootstrap.Modal(observacionesModalElement);
        }

        if (avisoModalElement && window.bootstrap && typeof window.bootstrap.Modal === 'function') {
            avisoModal = new window.bootstrap.Modal(avisoModalElement);
        }

        var $editForm = $('#report-edit-form');
        var $editFormAlert = $('#report-edit-form-alert');
        var $editSubmitButton = $('#report-edit-save');
        var $editSpinner = $('#report-edit-spinner');
        var $editClienteSelect = $('#report-edit-cliente-id');
        var $editClienteInput = $('#report-edit-cliente');
        var $editClienteInfo = $('#report-edit-cliente-info');
        var $editStatusSelect = $('#report-edit-status-id');
        var $editPedimentoInput = $('#pedimento');
        var $editCiplInput = $('#report-edit-cipl');
        var $editManifiestoInput = $('#report-edit-manifiesto');
        var $pedimentosFieldsGroup = $('#pedimentos-fields');
        var $pedimentosHeaderInput = $('#pedimentos-header-json');
        var $pedimentosItemsInput = $('#pedimentos-items-json');
        var $pedimentosPackagesInput = $('#pedimentos-packages-json');
        var $pedimentosBridgeFeedback = $('#pedimentos-bridge-feedback');
        var clienteInfoTemplate = $editClienteInfo.length ? ($editClienteInfo.attr('data-template') || '') : '';
        var editReferenceGroups = {};
        var editActionLabel = translate('reports.actions.edit', 'Edit');
        var observacionesActionLabel = translate('reports.actions.observaciones', 'Observaciones');
        var avisoActionLabel = translate('reports.actions.aviso', language === 'en' ? 'Notice' : 'Aviso');
        var expedienteActionLabel = language === 'en' ? 'Case file' : 'Expediente';
        var pendingOpenAvisoId = '';
        try {
            pendingOpenAvisoId = String(new URLSearchParams(window.location.search).get('open_aviso') || '').trim();
        } catch (urlSearchError) {
            pendingOpenAvisoId = '';
        }
        var avisoMissingLibraryMessage = translate(
            'reports.aviso_modal.error_missing_js',
            language === 'en'
                ? 'The PDF library is not available to prepare the notice.'
                : 'No fue posible preparar el aviso porque la librería de PDF no está disponible.'
        );
        var avisoMissingRecordMessage = translate(
            'reports.aviso_modal.error_no_record',
            language === 'en'
                ? 'The selected unloading record could not be found.'
                : 'No fue posible encontrar la información del desembarque seleccionado.'
        );
        var attachmentsEmptyText = translate('reports.attachments.empty', 'No attachments available.');
        var attachmentsActionLabel = attachmentsTitleText;

        if (!attachmentsActionLabel) {
            attachmentsActionLabel = language === 'en' ? 'Attachments' : 'Archivos adjuntos';
        }

        if (!attachmentsModalBaseTitle) {
            attachmentsModalBaseTitle = attachmentsActionLabel;
        }
        var attachmentsUploadMissingError = translate('desembarques.files.error_missing', 'Select a file to upload.');
        var attachmentsTooManyError = translate('desembarques.files.error_too_many', 'Too many files selected.', {
            max: maxAttachmentsPerRequest
        });
        var attachmentsValidationErrorTemplate = translate('desembarques.files.validation_error', 'The attachment could not be uploaded.');
        var attachmentsDeleteLabel = translate('reports.attachments.delete', 'Delete');
        var attachmentsDownloadLabel = translate('reports.attachments.download', 'Download');
        var attachmentsDeleteConfirm = translate('reports.attachments.delete_confirm', 'Do you want to delete this attachment?');
        var attachmentsDeleteConfirmButton = translate('reports.attachments.delete_confirm_button', 'Delete');
        var attachmentsDeleteCancelButton = translate('reports.attachments.delete_cancel_button', 'Cancel');
        var attachmentsUploadSuccess = translate('desembarques.files.upload_success', 'The attachment was uploaded successfully.');
        var attachmentsUploadErrorTemplate = translate(
            'desembarques.files.upload_error',
            'Unable to upload the attachment.'
        );
        var attachmentsDeleteSuccess = translate('desembarques.files.delete_success', 'The attachment was deleted successfully.');
        var attachmentsDeleteErrorTemplate = translate('desembarques.files.delete_error', 'Unable to delete the attachment.');
        var attachmentsTypeError = translate('dashboard.form.attachments_error_type', 'The selected file type is not allowed.');
        var attachmentsSizeErrorTemplate = translate('dashboard.form.attachments_error_size', 'The file exceeds the maximum allowed size ({max}).');
        var observationsEmptyText = translate('reports.observations_modal.empty', 'No observations yet.');
        var observationsLoadErrorText = translate('reports.observations_modal.load_error', 'Unable to load the observations.');
        var observationsCountFormat = translate('reports.observations_modal.count_format', 'Observations: {{count}}');
        var observationsMetaTemplate = translate('reports.observations_modal.meta', '{{author}} · {{date}}');
        var observationsMetaNoDateTemplate = translate('reports.observations_modal.meta_no_date', '{{author}}');
        var observationsYouLabel = translate('reports.observations_modal.you', 'You');
        var observationsLegacyAuthor = translate('reports.observations_modal.legacy_author', 'Original record');
        var $observacionesForm = $('#report-observaciones-form');
        var $observacionesAlert = $('#report-observaciones-alert');
        var $observacionesTextarea = $('#report-observaciones-text');
        var $observacionesSaveButton = $('#report-observaciones-save');
        var $observacionesSpinner = $('#report-observaciones-spinner');
        var $observacionesObservationId = $('#report-observaciones-observation-id');
        var $observacionesList = $('#report-observaciones-list');
        var $observacionesLoading = $('#report-observaciones-loading');
        var observationEditLabel = translate('reports.actions.edit', 'Edit');
        var observationsSaveLabel = $observacionesSaveButton.length ? $observacionesSaveButton.text() : '';
        var observacionesCache = {};
        var currentEditRecordId = null;
        var currentObservacionesRecordId = null;
        var currentObservationEditId = null;

        function clearAlert() {
            $alert.removeClass('alert-success alert-danger alert-warning alert-info');
            $alert.addClass('d-none').text('');
        }

        function showAlert(type, message) {
            $alert.removeClass('alert-success alert-danger alert-warning alert-info');
            $alert.addClass('alert-' + type).removeClass('d-none').text(message);
        }

        function resetAvisoAlert() {
            if ($avisoAlert.length) {
                $avisoAlert.addClass('d-none').text('');
            }
        }

        function showAvisoAlert(message) {
            if ($avisoAlert.length) {
                $avisoAlert.removeClass('d-none').text(message);
            } else {
                showAlert('warning', message);
            }
        }

        function setTableMessage($targetBody, targetColumnCount, message) {
            if (!$targetBody || !$targetBody.length) {
                return;
            }

            var totalColumns = Number(targetColumnCount);

            if (!Number.isFinite(totalColumns) || totalColumns <= 0) {
                totalColumns = 1;
            }

            $targetBody.html(
                '<tr><td colspan="' + totalColumns + '" class="text-center text-muted py-4">' + escapeHtml(String(message || '')) + '</td></tr>'
            );
        }

        function updateSummary(summary) {
            var totalCount = summary && typeof summary.count !== 'undefined' ? summary.count : 0;
            var totalDiasTranscurridos = summary && typeof summary.total_dias_transcurridos !== 'undefined' ? summary.total_dias_transcurridos : 0;
            var totalDiasFuera = summary && typeof summary.total_dias_fuera !== 'undefined' ? summary.total_dias_fuera : 0;

            $summaryCount.text(formatInteger(totalCount));
            $summaryDiasTranscurridos.text(formatInteger(totalDiasTranscurridos));
            $summaryDiasFuera.text(formatInteger(totalDiasFuera));
        }

        function buildDisplayValue(primary, secondary) {
            var primaryValue = primary || '';
            var secondaryValue = secondary || '';

            if (primaryValue && secondaryValue) {
                return primaryValue + ' (' + secondaryValue + ')';
            }

            return primaryValue || secondaryValue;
        }

        function clearEditFormAlert() {
            if (!$editFormAlert.length) {
                return;
            }

            $editFormAlert.removeClass('alert-success alert-danger alert-warning alert-info');
            $editFormAlert.addClass('d-none').text('');
        }

        function showEditFormAlert(type, message) {
            if (!$editFormAlert.length) {
                return;
            }

            $editFormAlert.removeClass('alert-success alert-danger alert-warning alert-info');
            $editFormAlert.addClass('alert-' + type).removeClass('d-none').text(message);
        }

        function clearEditFormErrors() {
            if (!$editForm.length) {
                return;
            }

            $editForm.find('.is-invalid').removeClass('is-invalid');
            $editForm.find('.invalid-feedback[data-feedback-for]').text('');
        }

        function displayEditFormErrors(errors) {
            if (!errors || !$editForm.length) {
                return;
            }

            Object.keys(errors).forEach(function (field) {
                if (!Object.prototype.hasOwnProperty.call(errors, field)) {
                    return;
                }

                var message = errors[field];
                if (message === undefined || message === null) {
                    message = '';
                }

                var normalizedField = field;
                var dotIndex = normalizedField.indexOf('.');
                if (dotIndex !== -1) {
                    normalizedField = normalizedField.slice(0, dotIndex);
                }

                var bracketIndex = normalizedField.indexOf('[');
                if (bracketIndex !== -1) {
                    normalizedField = normalizedField.slice(0, bracketIndex);
                }

                var $field = $editForm.find('[name="' + normalizedField + '"]');

                if (!$field.length) {
                    $field = $editForm.find('[name="' + normalizedField + '[]"]');
                }

                var $feedback = $editForm.find('.invalid-feedback[data-feedback-for="' + normalizedField + '"]');
                var referenceGroup = Object.prototype.hasOwnProperty.call(editReferenceGroups, normalizedField)
                    ? editReferenceGroups[normalizedField]
                    : null;

                if ($field.length) {
                    $field.addClass('is-invalid');
                }

                if (referenceGroup && typeof referenceGroup.markInvalid === 'function') {
                    referenceGroup.markInvalid();
                }

                if ($feedback.length) {
                    $feedback.text(String(message)).removeClass('d-none');
                }
            });
        }

        function clearEditAttachmentsFeedback() {
            if ($editAttachmentsFeedback.length) {
                $editAttachmentsFeedback.text('').addClass('d-none');
            }
        }

        function showEditAttachmentsFeedback(message) {
            if ($editAttachmentsFeedback.length) {
                $editAttachmentsFeedback.text(String(message || '')).removeClass('d-none');
            }
        }

        function resetEditAttachmentsInput() {
            pendingEditAttachments = [];

            if ($editAttachmentsInput.length) {
                $editAttachmentsInput.val('');
            }
        }

        function normalizeAttachment(attachment) {
            if (!attachment || typeof attachment !== 'object') {
                return null;
            }

            var idValue = Number(attachment.id || attachment.attachment_id || 0);
            var downloadUrl = '';

            if (attachment.download_url) {
                downloadUrl = String(attachment.download_url);
            } else if (idValue) {
                downloadUrl = '../api/desembarques/files/download.php?id=' + idValue;
            }

            return {
                id: idValue,
                original_name: String(attachment.original_name || ''),
                mime_type: String(attachment.mime_type || ''),
                extension: String(attachment.extension || ''),
                size: Number(attachment.size || 0),
                uploaded_by: Object.prototype.hasOwnProperty.call(attachment, 'uploaded_by')
                    ? (attachment.uploaded_by === null ? null : Number(attachment.uploaded_by))
                    : null,
                download_url: downloadUrl
            };
        }

        function normalizeAttachmentsList(attachments) {
            if (!Array.isArray(attachments)) {
                return [];
            }

            var normalized = [];

            attachments.forEach(function (attachment) {
                var normalizedAttachment = normalizeAttachment(attachment);

                if (normalizedAttachment) {
                    normalized.push(normalizedAttachment);
                }
            });

            return normalized;
        }

        function normalizeReferenceList(values) {
            var normalized = [];
            var seen = {};

            function pushValue(value) {
                if (value === null || typeof value === 'undefined') {
                    return;
                }

                var text = typeof value === 'string' ? value : String(value);
                text = text.trim();

                if (!text) {
                    return;
                }

                var key = text.toLowerCase();

                if (Object.prototype.hasOwnProperty.call(seen, key)) {
                    return;
                }

                seen[key] = true;
                normalized.push(text);
            }

            if (Array.isArray(values)) {
                values.forEach(pushValue);
            } else {
                pushValue(values);
            }

            return normalized;
        }

        function normalizePedimentoHeaders(headers) {
            if (!Array.isArray(headers)) {
                return [];
            }

            var normalized = [];

            headers.forEach(function (header) {
                if (!header || typeof header !== 'object') {
                    return;
                }

                var numberValue = '';

                if (Object.prototype.hasOwnProperty.call(header, 'num_pedimento')) {
                    var rawNumber = header.num_pedimento;

                    if (rawNumber !== null && typeof rawNumber !== 'undefined') {
                        numberValue = String(rawNumber).trim();
                    }
                }

                if (!numberValue && Object.prototype.hasOwnProperty.call(header, 'number')) {
                    var fallbackNumber = header.number;

                    if (fallbackNumber !== null && typeof fallbackNumber !== 'undefined') {
                        numberValue = String(fallbackNumber).trim();
                    }
                }

                var references = [];

                if (Array.isArray(header.pedimentos)) {
                    references = header.pedimentos;
                } else if (Array.isArray(header.references)) {
                    references = header.references;
                } else if (Array.isArray(header.links)) {
                    references = header.links;
                }

                var normalizedReferences = normalizeReferenceList(references);
                var normalizedNumber = numberValue;

                if (!normalizedNumber) {
                    normalizedNumber = '';
                }

                if (!normalizedNumber && normalizedReferences.length === 0) {
                    return;
                }

                normalized.push({
                    num_pedimento: normalizedNumber,
                    cve_pedimento: header.cve_pedimento ? String(header.cve_pedimento).trim() : '',
                    razon_social: header.razon_social ? String(header.razon_social).trim() : '',
                    fecha_entrada: header.fecha_entrada ? String(header.fecha_entrada).trim() : '',
                    fecha_pago: header.fecha_pago ? String(header.fecha_pago).trim() : '',
                    references: normalizedReferences
                });
            });

            if (normalized.length > 1) {
                normalized.sort(function (a, b) {
                    var numberA = a.num_pedimento || '';
                    var numberB = b.num_pedimento || '';

                    if (!numberA && !numberB) {
                        return 0;
                    }

                    if (!numberA) {
                        return 1;
                    }

                    if (!numberB) {
                        return -1;
                    }

                    return numberA.localeCompare(numberB, undefined, { numeric: true, sensitivity: 'base' });
                });
            }

            return normalized;
        }

        function normalizePedimentoPackagesForBridge(headers) {
            var normalized = [];

            if (!Array.isArray(headers)) {
                return normalized;
            }

            headers.forEach(function (header) {
                if (!header || typeof header !== 'object') {
                    return;
                }

                var numberValue = '';

                if (Object.prototype.hasOwnProperty.call(header, 'num_pedimento') && header.num_pedimento !== null && typeof header.num_pedimento !== 'undefined') {
                    numberValue = String(header.num_pedimento).trim();
                }

                var references = [];

                if (Array.isArray(header.references)) {
                    references = header.references;
                } else if (Array.isArray(header.pedimentos)) {
                    references = header.pedimentos;
                }

                var normalizedReferences = normalizeReferenceList(references);

                if (numberValue === '' && normalizedReferences.length === 0) {
                    return;
                }

                var packageEntry = {
                    header: numberValue !== '' ? { num_pedimento: numberValue } : {},
                    items: [],
                    pedimentos: normalizedReferences
                };

                if (Object.prototype.hasOwnProperty.call(header, 'id') && header.id !== null && typeof header.id !== 'undefined') {
                    packageEntry.id = String(header.id);
                }

                normalized.push(packageEntry);
            });

            return normalized;
        }

        function collectPedimentosFromPackages(packages) {
            var collected = [];

            if (!Array.isArray(packages)) {
                return collected;
            }

            packages.forEach(function (pkg) {
                if (!pkg || typeof pkg !== 'object') {
                    return;
                }

                var pedimentos = Array.isArray(pkg.pedimentos) ? pkg.pedimentos : [];

                pedimentos.forEach(function (value) {
                    var text = typeof value === 'string' ? value.trim() : String(value || '').trim();

                    if (!text) {
                        return;
                    }

                    collected.push(text);
                });
            });

            return collected;
        }

        function mergePrimaryReference(primaryValue, values) {
            var list = normalizeReferenceList(values);
            var primaryText = typeof primaryValue === 'string' ? primaryValue.trim() : '';

            if (primaryText) {
                var lowerPrimary = primaryText.toLowerCase();
                var exists = list.some(function (entry) {
                    return String(entry || '').toLowerCase() === lowerPrimary;
                });

                if (!exists) {
                    list.unshift(primaryText);
                }
            }

            return list;
        }

        function initEditReferenceGroup(groupName) {
            if (!$editForm.length) {
                return;
            }

            var $group = $editForm.find('[data-reference-group="' + groupName + '"]');

            if (!$group.length) {
                return;
            }

            var fieldName = $group.attr('data-field-name') || groupName;
            var placeholder = $group.attr('data-placeholder') || '';
            var labelId = $group.attr('data-label-id') || '';
            var labelText = $group.attr('data-label') || '';
            var removeText = $group.attr('data-remove-text') || translate(
                'dashboard.form.remove_field',
                language === 'en' ? 'Remove' : 'Eliminar'
            );
            var removeAria = $group.attr('data-remove-aria') || '';

            if (!removeAria && removeText) {
                removeAria = labelText
                    ? removeText + ' ' + labelText.toLowerCase()
                    : removeText;
            }

            var inputPrefix = $group.attr('data-input-prefix') || ('report-edit-' + groupName);
            var maxLengthValue = parseInt($group.attr('data-max-length'), 10);

            if (!Number.isFinite(maxLengthValue) || maxLengthValue <= 0) {
                maxLengthValue = 100;
            }

            function applyInputAccessibility($input) {
                if (!$input || !$input.length) {
                    return;
                }

                if (labelId) {
                    $input.attr('aria-labelledby', labelId);
                    $input.removeAttr('aria-label');
                } else if (labelText) {
                    $input.attr('aria-label', labelText);
                    $input.removeAttr('aria-labelledby');
                } else {
                    $input.removeAttr('aria-label');
                    $input.removeAttr('aria-labelledby');
                }
            }

            function createField(value, index) {
                var sanitized = typeof value === 'string' ? value : String(value || '');
                sanitized = sanitized.trim();

                var $wrapper = $('<div></div>')
                    .addClass('input-group reference-field mb-2')
                    .attr('data-index', index);
                var inputId = inputPrefix + '-' + index;
                var $input = $('<input type="text" class="form-control">')
                    .attr({
                        id: inputId,
                        name: fieldName + '[]',
                        maxlength: maxLengthValue,
                        placeholder: placeholder
                    })
                    .val(sanitized);

                applyInputAccessibility($input);

                var $removeButton = $('<button type="button" class="btn btn-outline-danger" data-action="remove-reference"></button>')
                    .text(removeText);

                if (removeAria) {
                    $removeButton.attr('aria-label', removeAria);
                }

                $wrapper.append($input).append($removeButton);

                return $wrapper;
            }

            function updateRemoveButtons() {
                var $fields = $group.find('.reference-field');

                if ($fields.length <= 1) {
                    $fields.each(function () {
                        $(this)
                            .find('[data-action="remove-reference"]')
                            .attr('disabled', true)
                            .addClass('disabled');
                    });
                } else {
                    $fields.each(function () {
                        $(this)
                            .find('[data-action="remove-reference"]')
                            .attr('disabled', false)
                            .removeClass('disabled');
                    });
                }
            }

            function setValues(values) {
                var list = [];

                if (Array.isArray(values)) {
                    values.forEach(function (value) {
                        var text = typeof value === 'string' ? value.trim() : String(value || '').trim();

                        if (text) {
                            list.push(text);
                        }
                    });
                }

                if (list.length === 0) {
                    list.push('');
                }

                $group.empty();

                list.forEach(function (value, index) {
                    var $field = createField(value, index);
                    $group.append($field);
                });

                updateRemoveButtons();
            }

            function appendField(value) {
                var index = $group.find('.reference-field').length;
                var $field = createField(value || '', index);

                $group.append($field);
                updateRemoveButtons();

                window.setTimeout(function () {
                    var $input = $field.find('input').first();

                    if ($input.length) {
                        $input.trigger('focus');
                    }
                }, 0);
            }

            $group.on('click', '[data-action="remove-reference"]', function (event) {
                event.preventDefault();

                var $button = $(this);

                if ($button.is(':disabled')) {
                    return;
                }

                var $fields = $group.find('.reference-field');

                if ($fields.length <= 1) {
                    return;
                }

                $button.closest('.reference-field').remove();
                updateRemoveButtons();
            });

            var $addButton = $editForm.find('[data-reference-add="' + groupName + '"]');

            if ($addButton.length) {
                $addButton.on('click', function (event) {
                    event.preventDefault();
                    appendField('');
                });
            }

            editReferenceGroups[groupName] = {
                setValues: setValues,
                markInvalid: function () {
                    $group.find('input').addClass('is-invalid');
                }
            };

            setValues([]);
        }

        if ($pedimentosFieldsGroup.length) {
            editReferenceGroups.pedimentos = {
                setValues: function (values) {
                    var api = $pedimentosFieldsGroup.data('dynamicFieldApi');
                    if (!api || typeof api.setValues !== 'function') {
                        return;
                    }

                    var list = Array.isArray(values) ? values.slice() : [];
                    api.setValues(list);

                    window.setTimeout(function () {
                        $pedimentosFieldsGroup.find('input').trigger('change');
                    }, 0);
                },
                markInvalid: function () {
                    $pedimentosFieldsGroup.find('input').addClass('is-invalid');
                }
            };
        }
        initEditReferenceGroup('manifests');
        initEditReferenceGroup('cipls');

        function getRecordAttachments(recordId) {
            if (!recordId) {
                return [];
            }

            var record = Object.prototype.hasOwnProperty.call(recordsById, recordId)
                ? recordsById[recordId]
                : null;

            if (!record || !Array.isArray(record.attachments)) {
                return [];
            }

            return record.attachments.slice();
        }

        function setRecordAttachments(recordId, attachments) {
            if (!recordId) {
                return;
            }

            var normalized = normalizeAttachmentsList(attachments);

            if (Object.prototype.hasOwnProperty.call(recordsById, recordId) && recordsById[recordId]) {
                recordsById[recordId].attachments = normalized.slice();
            }

            if (Array.isArray(currentRecords)) {
                currentRecords.forEach(function (record) {
                    if (!record) {
                        return;
                    }

                    var idValue = typeof record.id !== 'undefined' ? String(record.id) : '';

                    if (idValue === recordId) {
                        record.attachments = normalized.slice();
                    }
                });
            }

            if (currentEditRecordId === recordId) {
                renderEditAttachmentsList(recordId);
            }
        }

        function renderAttachmentsModal(recordId, attachments) {
            if (!$attachmentsModalList.length || !$attachmentsModalEmpty.length) {
                return;
            }

            var items = Array.isArray(attachments)
                ? attachments
                : getRecordAttachments(recordId);
            var normalizedItems = normalizeAttachmentsList(items);

            if ($attachmentsModalList.length) {
                $attachmentsModalList.attr('aria-busy', 'true');
            }

            var itemsHtml = normalizedItems.map(function (attachment) {
                if (!attachment) {
                    return '';
                }

                var name = attachment.original_name || (language === 'en' ? 'File' : 'Archivo');
                var sizeLabel = attachment.size && attachment.size > 0 ? formatFileSize(attachment.size) : '';
                var createdAt = typeof attachment.created_at_display === 'string'
                    ? attachment.created_at_display.trim()
                    : '';
                var linkHtml;

                if (attachment.download_url) {
                    linkHtml = '<a href="' + escapeHtml(attachment.download_url) + '" class="fw-semibold" target="_blank" rel="noopener noreferrer" title="' + escapeHtml(attachmentsDownloadLabel) + '">' + escapeHtml(name) + '</a>';
                } else {
                    linkHtml = '<span class="fw-semibold">' + escapeHtml(name) + '</span>';
                }

                var metaParts = [];

                if (sizeLabel) {
                    metaParts.push(sizeLabel);
                }

                if (createdAt) {
                    metaParts.push(createdAt);
                }

                var metaHtml = metaParts.length
                    ? '<span class="text-muted small">' + escapeHtml(metaParts.join(' · ')) + '</span>'
                    : '';

                return '<li class="list-group-item">'
                    + '<div class="d-flex flex-column gap-1">'
                        + linkHtml
                        + metaHtml
                    + '</div>'
                + '</li>';
            }).filter(function (html) {
                return html !== '';
            }).join('');

            if (!itemsHtml) {
                $attachmentsModalList.empty().addClass('d-none');
                $attachmentsModalEmpty.text(attachmentsEmptyText).removeClass('d-none');
            } else {
                $attachmentsModalList.html(itemsHtml).removeClass('d-none');
                $attachmentsModalEmpty.addClass('d-none');
            }

            if ($attachmentsModalList.length) {
                $attachmentsModalList.attr('aria-busy', 'false');
            }
        }

        function openAttachmentsModal(recordId) {
            var normalizedRecordId = String(recordId || '').trim();

            if (!normalizedRecordId) {
                return;
            }

            var attachments = getRecordAttachments(normalizedRecordId);

            if (!attachmentsModal) {
                if (attachments.length && attachments[0] && attachments[0].download_url) {
                    try {
                        window.open(attachments[0].download_url, '_blank', 'noopener');
                    } catch (error) {
                        // Ignore errors from blocked pop-ups.
                    }
                }

                return;
            }

            if ($attachmentsModalTitle.length) {
                var modalTitle = attachmentsModalBaseTitle || attachmentsActionLabel;
                var record = Object.prototype.hasOwnProperty.call(recordsById, normalizedRecordId)
                    ? recordsById[normalizedRecordId]
                    : null;
                var reference = record && record.referencia ? String(record.referencia).trim() : '';

                if (reference) {
                    modalTitle = modalTitle + ' — ' + reference;
                }

                $attachmentsModalTitle.text(modalTitle);
            }

            renderAttachmentsModal(normalizedRecordId, attachments);

            attachmentsModal.show();
        }

        function buildAttachmentsCellHtml(recordId, attachments) {
            var items = Array.isArray(attachments) ? attachments : [];
            var normalizedRecordId = String(recordId || '').trim();

            if (!normalizedRecordId) {
                return '<td><span class="text-muted">' + escapeHtml(notAvailableText) + '</span></td>';
            }

            var count = items.length;

            if (count === 0) {
                return '<td><span class="text-muted">' + escapeHtml(attachmentsEmptyText) + '</span></td>';
            }

            var label = attachmentsActionLabel || (language === 'en' ? 'Attachments' : 'Archivos adjuntos');

            if (count > 0) {
                label += ' (' + escapeHtml(formatInteger(count)) + ')';
            }

            return '<td>'
                + '<button type="button" class="btn btn-outline-secondary btn-sm" data-action="view-attachments" data-id="' + escapeHtml(normalizedRecordId) + '">'
                    + escapeHtml(label)
                + '</button>'
            + '</td>';
        }

        function formatAvisoDate(value, forceSpanish) {
            if (value === null || value === undefined) {
                return '';
            }

            var date;

            if (value instanceof Date) {
                date = value;
            } else {
                var rawValue = String(value || '').trim();

                if (!rawValue) {
                    return '';
                }

                var parsedDate = new Date(rawValue);

                if (Number.isNaN(parsedDate.getTime())) {
                    return rawValue;
                }
                date = parsedDate;
            }

            var locale = forceSpanish ? 'es-MX' : (language === 'en' ? 'en-US' : 'es-MX');
            var formatter;

            try {
                formatter = new Intl.DateTimeFormat(locale, {
                    day: 'numeric',
                    month: 'long',
                    year: 'numeric'
                });
            } catch (error) {
                formatter = new Intl.DateTimeFormat('es-MX', {
                    day: 'numeric',
                    month: 'long',
                    year: 'numeric'
                });
            }

            var formatted = formatter.format(date);

            if (forceSpanish || language !== 'en') {
                formatted = formatted.toUpperCase();
            }

            return formatted;
        }

        function parseAvisoList(value) {
            return String(value || '')
                .split(/\r?\n/)
                .map(function (line) {
                    return line.trim();
                })
                .filter(function (line) {
                    return line !== '';
                });
        }

        function parseAvisoItems(value) {
            var lines = parseAvisoList(value);

            return lines.map(function (line) {
                var quantity = '';
                var description = line;

                if (line.indexOf('|') !== -1) {
                    var parts = line.split('|');
                    quantity = parts.shift().trim();
                    description = parts.join('|').trim();
                } else if (line.indexOf('\t') !== -1) {
                    var tabParts = line.split('\t');
                    quantity = tabParts.shift().trim();
                    description = tabParts.join(' ').trim();
                } else if (line.indexOf(';') !== -1) {
                    var semicolonParts = line.split(';');
                    quantity = semicolonParts.shift().trim();
                    description = semicolonParts.join(';').trim();
                }

                return {
                    quantity: quantity,
                    description: description
                };
            });
        }

        function loadAvisoLogo() {
            if (avisoLogoPromise) {
                return avisoLogoPromise;
            }

            var configuredLogoUrl = typeof reportConfig.avisoLogoUrl === 'string'
                ? reportConfig.avisoLogoUrl.trim()
                : '';
            var logoUrl = configuredLogoUrl || 'assets/img/logo-gg.png';

            avisoLogoPromise = new Promise(function (resolve) {
                if (!logoUrl) {
                    resolve(null);

                    return;
                }

                var image = new Image();
                var finished = false;

                function finalize(result) {
                    if (finished) {
                        return;
                    }

                    finished = true;
                    resolve(result);
                }

                image.onload = function () {
                    var width = Number(image.naturalWidth || image.width || 0);
                    var height = Number(image.naturalHeight || image.height || 0);

                    try {
                        if (width > 0 && height > 0) {
                            var canvas = document.createElement('canvas');
                            canvas.width = width;
                            canvas.height = height;

                            var context = canvas.getContext('2d');

                            if (context && typeof context.drawImage === 'function') {
                                context.drawImage(image, 0, 0, width, height);
                                var dataUrl = canvas.toDataURL('image/png');

                                finalize({
                                    dataUrl: dataUrl,
                                    width: width,
                                    height: height
                                });

                                return;
                            }
                        }
                    } catch (error) {
                        // Ignore drawing errors and fall back to the raw image element.
                    }

                    finalize({
                        element: image,
                        width: width,
                        height: height
                    });
                };

                image.onerror = function () {
                    finalize(null);
                };

                image.src = logoUrl;
            }).catch(function () {
                return null;
            });

            return avisoLogoPromise;
        }

        function loadAvisoBackground() {
            if (avisoBackgroundPromise) {
                return avisoBackgroundPromise;
            }

            if (!avisoBackgroundUrl) {
                avisoBackgroundPromise = Promise.resolve(null);

                return avisoBackgroundPromise;
            }

            avisoBackgroundPromise = new Promise(function (resolve) {
                var image = new Image();
                var finished = false;

                function finalize(result) {
                    if (finished) {
                        return;
                    }

                    finished = true;
                    resolve(result);
                }

                image.onload = function () {
                    var width = Number(image.naturalWidth || image.width || 0);
                    var height = Number(image.naturalHeight || image.height || 0);

                    try {
                        if (width > 0 && height > 0) {
                            var canvas = document.createElement('canvas');
                            canvas.width = width;
                            canvas.height = height;

                            var context = canvas.getContext('2d');

                            if (context && typeof context.drawImage === 'function') {
                                context.drawImage(image, 0, 0, width, height);
                                var dataUrl = canvas.toDataURL('image/png');

                                finalize({
                                    dataUrl: dataUrl,
                                    width: width,
                                    height: height
                                });

                                return;
                            }
                        }
                    } catch (error) {
                        // Ignore drawing errors and fall back to the raw image element.
                    }

                    finalize({
                        element: image,
                        width: width,
                        height: height
                    });
                };

                image.onerror = function () {
                    finalize(null);
                };

                image.src = avisoBackgroundUrl;
            }).catch(function () {
                return null;
            });

            return avisoBackgroundPromise;
        }

        function loadAvisoWatermark() {
            if (avisoWatermarkPromise) {
                return avisoWatermarkPromise;
            }

            if (!avisoWatermarkUrl) {
                avisoWatermarkPromise = Promise.resolve(null);

                return avisoWatermarkPromise;
            }

            avisoWatermarkPromise = new Promise(function (resolve) {
                var image = new Image();
                var finished = false;

                function finalize(result) {
                    if (finished) {
                        return;
                    }

                    finished = true;
                    resolve(result);
                }

                image.onload = function () {
                    var width = Number(image.naturalWidth || image.width || 0);
                    var height = Number(image.naturalHeight || image.height || 0);

                    try {
                        if (width > 0 && height > 0) {
                            var canvas = document.createElement('canvas');
                            canvas.width = width;
                            canvas.height = height;

                            var context = canvas.getContext('2d');

                            if (context && typeof context.drawImage === 'function') {
                                context.drawImage(image, 0, 0, width, height);
                                var dataUrl = canvas.toDataURL('image/png');

                                finalize({
                                    dataUrl: dataUrl,
                                    width: width,
                                    height: height
                                });

                                return;
                            }
                        }
                    } catch (error) {
                        // Ignore drawing errors and fall back to the raw image element.
                    }

                    finalize({
                        element: image,
                        width: width,
                        height: height
                    });
                };

                image.onerror = function () {
                    finalize(null);
                };

                image.src = avisoWatermarkUrl;
            }).catch(function () {
                return null;
            });

            return avisoWatermarkPromise;
        }

        function resolveAvisoContact(type) {
            if (!type) {
                return {
                    name: '',
                    title: ''
                };
            }

            var contact = Object.prototype.hasOwnProperty.call(avisoContacts, type)
                ? avisoContacts[type]
                : null;

            if (!contact || typeof contact !== 'object') {
                return {
                    name: '',
                    title: ''
                };
            }

            var names = contact.name && typeof contact.name === 'object' ? contact.name : {};
            var titles = contact.title && typeof contact.title === 'object' ? contact.title : {};

            function resolveValue(values) {
                if (!values || typeof values !== 'object') {
                    return '';
                }

                if (language === 'en') {
                    var englishValue = values.en && typeof values.en === 'string' ? values.en.trim() : '';

                    if (englishValue) {
                        return englishValue;
                    }

                    return values.es && typeof values.es === 'string' ? values.es.trim() : '';
                }

                var spanishValue = values.es && typeof values.es === 'string' ? values.es.trim() : '';

                if (spanishValue) {
                    return spanishValue;
                }

                return values.en && typeof values.en === 'string' ? values.en.trim() : '';
            }

            return {
                name: resolveValue(names),
                title: resolveValue(titles)
            };
        }

        function buildAvisoDefaults(record) {
            var safeRecord = record || {};
            var referencia = safeRecord.referencia || '';
            var vessel = safeRecord.barco || '';
            var destination = safeRecord.destino || '';
            var clientName = safeRecord.client_name || safeRecord.cliente || '';
            var createdByName = safeRecord.created_by_name || '';
            var noticeNumberValue = safeRecord.manifiesto || safeRecord.folio_aviso || referencia || '';
            var landingDateRaw = safeRecord.fecha_desembarque || '';
            var landingDisplay = safeRecord.fecha_desembarque_display || landingDateRaw || '';
            var departureDisplay = safeRecord.fecha_embarque_display || safeRecord.fecha_embarque || '';
            var formattedDate = formatAvisoDate(landingDateRaw, true);
            var pedimentosArray = normalizeReferenceList(safeRecord.pedimentos);
            var placeholders = {
                noticeNumber: '{{FOLIO_AVISO}}',
                landingDate: '{{FECHA_DESEMBARQUE}}',
                pedimento: '{{PEDIMENTO}}',
                goods: '{{DESCRIPCION_MERCANCIAS}}',
                project: '{{PROYECTO}}',
                vessel: '{{NOMBRE_EMBARCACION}}',
                terminal: '{{TERMINAL}}',
                destinationAddress: '{{DOMICILIO_DESTINO}}',
                principal: '{{COMITENTE}}',
                officialEs: '{{TITULAR_ADUANA}}',
                officialEn: '{{CUSTOMS_OFFICIAL}}'
            };

            function withPlaceholder(value, placeholder) {
                var text = typeof value === 'string' ? value.trim() : '';

                if (text) {
                    return text;
                }

                return placeholder;
            }

            var noticeNumberText = withPlaceholder(noticeNumberValue, placeholders.noticeNumber);
            var landingText = withPlaceholder(formattedDate || landingDisplay, placeholders.landingDate);
            var pedimentoText = withPlaceholder(
                (pedimentosArray.length ? pedimentosArray[0] : '')
                    || safeRecord.pedimento
                    || safeRecord.pedimento_clave
                    || safeRecord.pedimento_numero
                    || '',
                placeholders.pedimento
            );
            var goodsText = withPlaceholder(
                safeRecord.mercancia || safeRecord.mercancias || safeRecord.descripcion || safeRecord.descripcion_mercancia || '',
                placeholders.goods
            );
            var projectText = withPlaceholder(safeRecord.proyecto || safeRecord.proyecto_nombre || '', placeholders.project);
            var vesselText = withPlaceholder(vessel, placeholders.vessel);
            var terminalText = withPlaceholder(safeRecord.terminal || safeRecord.terminal_nombre || '', placeholders.terminal);
            var destinationAddressText = withPlaceholder(
                safeRecord.domicilio_destino || safeRecord.destino_domicilio || '',
                placeholders.destinationAddress
            );
            var principalSource = safeRecord.comitente || safeRecord.razon_social || safeRecord.principal || clientName;
            var principalText = withPlaceholder(principalSource, placeholders.principal);
            var recipientContact = resolveAvisoContact('recipient');
            var signerContact = resolveAvisoContact('signer');
            var locationDateText = formatAvisoDate(new Date(), true);

            if (!locationDateText) {
                locationDateText = landingText;
            }

            if (!locationDateText) {
                locationDateText = placeholders.landingDate;
            }

            // The official Mexican filing keeps its authorized Spanish wording,
            // regardless of the language selected for the application interface.
            var locationLine = 'Tampico Tamaulipas a ' + locationDateText + '.';
            var documentTitle = 'AVISO DE DESEMBARQUE, DE CONFORMIDAD CON LOS PARRAFOS CUARTO Y QUINTO DE LA REGLA 4.2.11 DE LAS REGLAS GENERALES DE COMERCIO EXTERIOR. DE MERCANCIAS DESINCORPORADAS Y/O DE MERCANCIAS IMPORTADAS TEMPORALMENTE DESTINADAS Y PROVENIENTES DEL BUQUE DE PERFORACIÓN DE DOBLE ACTIVIDAD EN AGUAS ULTRA PROFUNDAS DENOMINADO {{NOMBRE_RIG}}, CON NÚMERO IMO {{IMO_RIG}}, POSICIONADO EN EL CAMPO {{CAMPO_RIG}}.';
            var recipientLines = [];
            var officialPlaceholder = placeholders.officialEs;
            var officialNameSource = safeRecord.titular_aduana || safeRecord.customs_official || safeRecord.destinatario || '';
            var officialNameText = '';

            if (typeof officialNameSource === 'string') {
                officialNameText = officialNameSource.trim();
            }

            if (!officialNameText) {
                officialNameText = recipientContact.name;
            }

            if (!officialNameText) {
                officialNameText = officialPlaceholder;
            }

            var recipientTitleFallback = 'TITULAR DE LA ADUANA DE TAMPICO';
            var recipientTitleText = recipientContact.title || recipientTitleFallback;

            if (!recipientTitleText) {
                recipientTitleText = recipientTitleFallback;
            }

            if (/^(C\.|C\s|LIC\.|ING\.|MTRO\.|MTRA\.|DR\.|DRA\.)/i.test(officialNameText)) {
                recipientLines.push(officialNameText);
            } else {
                recipientLines.push('C. ' + officialNameText);
            }

            if (recipientTitleText) {
                recipientLines.push(recipientTitleText);
            }

            var introduction;
            var body;
            var operations;
            var closing;
            var documentationItems;

            introduction = 'Quien suscribe el presente Javier Gerez Bazan, Mexicano, mayor de edad, con Registro Federal de Contribuyentes GEBJ8001191K9, con domicilio fiscal el ubicado en calle Saturno #100, Col. Anahuac, C.P.89180, Tampico Tamaulipas y domicilio dentro de la circunscripción territorial de esa Aduana de Tampico, para oír y recibir notificaciones el declarado como domicilio fiscal, con teléfono (833) 214-00-41, de ocupación Agente Aduanal, con número de Patente Nacional 1948 con autorización para actuar ante la Aduana de Altamira, Tampico y Matamoros por este conducto, en los términos del artículo 8 de la Constitución Política de los Estados Unidos Mexicanos, artículos 18 y 18A del Código Fiscal de la Federación y artículo 41 de la Ley Aduanera, autorizando en los términos del artículo 19, cuarto párrafo del Código Fiscal de la Federación a los C. Victor Cándido Ibarias Toledo gafete número 95045, Daniel Iván Guadalupe Nieto gafete número 95771, Juan Pablo Alfaro Soto gafete número 270 y Filiberto Salas Gonzalez gafete número 96247, todos ellos dependientes autorizados de mi patente 1948, con correos electrónicos kevin.guzman@grupogerez.com y victor.ibarias@grupogerez.com, y teléfonos (833) 2601033 y (833) 2140041, ante usted con el debido respeto, mediante el presente, con fundamento en el artículo 41 de la Ley Aduanera, en nombre de mi comitente {{COMITENTE}}, Presento Aviso de DESEMBARQUE de conformidad con los párrafos cuarto y quinto de la regla 4.2.11 de las Reglas Generales de Comercio Exterior, de mercancías desincorporadas y/o de mercancías importadas temporalmente destinadas y provenientes del buque de perforación de doble actividad en aguas ultra profundas denominado {{NOMBRE_RIG}}, con número IMO {{IMO_RIG}}, posicionado en el campo {{CAMPO_RIG}}, para lo anterior se declara la siguiente información:';
            body = '';
            operations = '';
            closing = 'Por lo antes expuesto, respetuosamente en espera de que el presente cumpla con la normatividad vigente para efectos legales de lo que en el presente se declara, agradezco la atención que brinde al presente, quedando a sus órdenes para cualquier duda o aclaración al respecto.';
            documentationItems = [
                'Copia de pedimento',
                'Copia de Manifiesto de desembarque',
                'Copia de Carta de responsabilidad solidaria'
            ];

            var noticeNumber = noticeNumberText;
            var defaultSignerName = signerContact.name || createdByName || 'Javier Gerez Bazan';
            var signerTitleFallback = 'AGENTE ADUANAL PATENTE 1948';
            var defaultSignerTitle = signerContact.title || signerTitleFallback;

            if (!defaultSignerTitle) {
                defaultSignerTitle = signerTitleFallback;
            }

            var footerText = avisoFooter;
            var pedimentoHeadersDetailed = normalizePedimentoHeaders(safeRecord.pedimento_headers);
            var itemsLines = [];

            if (pedimentoHeadersDetailed.length) {
                pedimentoHeadersDetailed.forEach(function (header, index) {
                    var headerNumber = typeof header.num_pedimento === 'string'
                        ? header.num_pedimento.trim()
                        : '';
                    var headerReferences = Array.isArray(header.references)
                        ? header.references.slice()
                        : [];
                    var hasContent = false;

                    if (headerNumber) {
                        itemsLines.push(headerNumber);
                        hasContent = true;
                    }

                    headerReferences.forEach(function (reference) {
                        var text = typeof reference === 'string' ? reference.trim() : '';

                        if (!text) {
                            return;
                        }

                        itemsLines.push('- ' + text);
                        hasContent = true;
                    });

                    if (hasContent && index < pedimentoHeadersDetailed.length - 1) {
                        itemsLines.push('');
                    }
                });

                while (itemsLines.length && itemsLines[itemsLines.length - 1] === '') {
                    itemsLines.pop();
                }
            } else {
                var pedimentoReferences = [];

                if (record && Array.isArray(record.pedimento_header_references)) {
                    pedimentoReferences = record.pedimento_header_references.slice();
                } else if (
                    record &&
                    record.pedimento_header_references &&
                    typeof record.pedimento_header_references === 'object'
                ) {
                    pedimentoReferences = Object.keys(record.pedimento_header_references)
                        .map(function (key) {
                            return record.pedimento_header_references[key];
                        });
                }

                itemsLines = normalizeReferenceList(pedimentoReferences);
            }

            var itemsText = itemsLines.join('\n');

            var documentCode = safeRecord.folio_aviso || (noticeNumberText && noticeNumberText.indexOf('{{') !== 0 ? 'MADE-' + noticeNumberText : '');
            var rigName = safeRecord.rig_name || safeRecord.rig || '';
            var rigImo = safeRecord.rig_imo || '';
            var rigField = safeRecord.rig_field || safeRecord.campo || '';
            var rigArea = safeRecord.rig_area || safeRecord.area_rig || 'CUBIERTA';

            return {
                documentCode: documentCode,
                rigName: rigName,
                rigImo: rigImo,
                rigField: rigField,
                rigArea: rigArea,
                comitente: principalSource || '',
                documentTitle: documentTitle,
                noticeNumber: noticeNumber,
                locationDate: locationLine,
                recipient: recipientLines.join('\n'),
                introduction: introduction,
                body: body,
                operations: operations,
                items: itemsText,
                documentation: '',
                closing: closing,
                signerName: defaultSignerName,
                signerTitle: defaultSignerTitle,
                footer: footerText,
                landingDisplay: landingDisplay,
                departureDisplay: departureDisplay
            };
        }

        function updateAvisoSummary(record, defaults) {
            if (!$avisoSummaryReference.length && !$avisoSummaryVessel.length && !$avisoSummaryDestination.length && !$avisoSummaryDates.length) {
                return;
            }

            var safeRecord = record || {};
            var defaultValues = defaults || {};
            var referenceValue = safeRecord.referencia || safeRecord.folio_aviso || notAvailableText;
            var vesselValue = safeRecord.barco || notAvailableText;
            var destinationValue = safeRecord.destino || notAvailableText;
            var landingValue = defaultValues.landingDisplay || safeRecord.fecha_desembarque_display || safeRecord.fecha_desembarque || '';
            var departureValue = defaultValues.departureDisplay || safeRecord.fecha_embarque_display || safeRecord.fecha_embarque || '';

            if (!landingValue) {
                landingValue = notAvailableText;
            }

            if (!departureValue) {
                departureValue = notAvailableText;
            }

            if ($avisoSummaryReference.length) {
                $avisoSummaryReference.text(
                    translate('reports.aviso_modal.summary_reference', 'Reference: {{value}}', {
                        value: referenceValue || notAvailableText
                    })
                );
            }

            if ($avisoSummaryVessel.length) {
                $avisoSummaryVessel.text(
                    translate('reports.aviso_modal.summary_vessel', 'Vessel: {{value}}', {
                        value: vesselValue || notAvailableText
                    })
                );
            }

            if ($avisoSummaryDestination.length) {
                $avisoSummaryDestination.text(
                    translate('reports.aviso_modal.summary_destination', 'Destination: {{value}}', {
                        value: destinationValue || notAvailableText
                    })
                );
            }

            if ($avisoSummaryDates.length) {
                $avisoSummaryDates.text(
                    translate('reports.aviso_modal.summary_dates', 'Unloading: {{landing}} · Departure: {{departure}}', {
                        landing: landingValue,
                        departure: departureValue
                    })
                );
            }
        }

        function fillAvisoForm(record) {
            var defaults = buildAvisoDefaults(record);
            var recordId = record && typeof record.id !== 'undefined' ? String(record.id) : '';

            if ($avisoRecordIdInput.length) {
                $avisoRecordIdInput.val(recordId);
            }

            $avisoDocumentCodeInput.val(defaults.documentCode || '');
            $avisoRigNameInput.val(defaults.rigName || '');
            $avisoRigImoInput.val(defaults.rigImo || '');
            $avisoRigFieldInput.val(defaults.rigField || '');
            $avisoRigAreaInput.val(defaults.rigArea || '');
            $avisoComitenteInput.val(defaults.comitente || '');
            $avisoDocumentTitleInput.val(defaults.documentTitle);
            $avisoNoticeNumberInput.val(defaults.noticeNumber);
            $avisoLocationDateInput.val(defaults.locationDate);
            $avisoRecipientInput.val(defaults.recipient);
            $avisoIntroductionInput.val(defaults.introduction);
            $avisoBodyInput.val(defaults.body);
            $avisoOperationsInput.val(defaults.operations);
            $avisoItemsInput.val(defaults.items);
            $avisoDocumentationInput.val(defaults.documentation);
            $avisoClosingInput.val(defaults.closing);
            $avisoSignerNameInput.val(defaults.signerName);
            $avisoSignerTitleInput.val(defaults.signerTitle);
            $avisoFooterInput.val(defaults.footer);

            return defaults;
        }

        function collectAvisoFormData() {
            return {
                profileId: String($avisoProfileSelect.val() || '').trim(),
                documentCode: String($avisoDocumentCodeInput.val() || '').trim(),
                rigName: String($avisoRigNameInput.val() || '').trim(),
                rigImo: String($avisoRigImoInput.val() || '').trim(),
                rigField: String($avisoRigFieldInput.val() || '').trim(),
                rigArea: String($avisoRigAreaInput.val() || '').trim(),
                comitente: String($avisoComitenteInput.val() || '').trim(),
                documentTitle: String($avisoDocumentTitleInput.val() || '').trim(),
                noticeNumber: String($avisoNoticeNumberInput.val() || '').trim(),
                locationDate: String($avisoLocationDateInput.val() || '').trim(),
                recipient: String($avisoRecipientInput.val() || '').trim(),
                introduction: String($avisoIntroductionInput.val() || '').trim(),
                body: String($avisoBodyInput.val() || '').trim(),
                operations: String($avisoOperationsInput.val() || '').trim(),
                items: String($avisoItemsInput.val() || '').trim(),
                documentation: String($avisoDocumentationInput.val() || '').trim(),
                closing: String($avisoClosingInput.val() || '').trim(),
                signerName: String($avisoSignerNameInput.val() || '').trim(),
                signerTitle: String($avisoSignerTitleInput.val() || '').trim(),
                footer: String($avisoFooterInput.val() || '').trim()
            };
        }

        function normalizeAvisoFileName(value) {
            var base = String(value || '')
                .toLowerCase()
                .replace(/[^a-z0-9]+/gi, '-');

            base = base.replace(/-+/g, '-').replace(/^-|-$/g, '');

            if (!base) {
                base = 'aviso';
            }

            return base;
        }

        function normalizeAvisoPedimentoKey(value) {
            return String(value || '').toLowerCase().replace(/[^0-9a-z]+/g, '');
        }

        function normalizeAvisoClaveKey(value) {
            return String(value || '').toUpperCase().replace(/\s+/g, '');
        }

        function getAvisoState(recordId) {
            var key = String(recordId || '').trim();
            if (!key) {
                return null;
            }
            return Object.prototype.hasOwnProperty.call(avisoDataByRecord, key) ? avisoDataByRecord[key] : null;
        }

        function getAvisoProfilesState(recordId) {
            var key = String(recordId || '').trim();
            if (!key) {
                return null;
            }
            return Object.prototype.hasOwnProperty.call(avisoProfilesByRecord, key) ? avisoProfilesByRecord[key] : null;
        }

        function resolveProfileContact(profile, type) {
            var contact = profile && profile[type] && typeof profile[type] === 'object' ? profile[type] : {};
            var name = language === 'en'
                ? String(contact.name_en || contact.name_es || '').trim()
                : String(contact.name_es || contact.name_en || '').trim();
            var title = language === 'en'
                ? String(contact.title_en || contact.title_es || '').trim()
                : String(contact.title_es || contact.title_en || '').trim();
            return { name: name, title: title };
        }

        function formatAvisoRecipientText(name, title) {
            var cleanName = String(name || '').trim();
            var cleanTitle = String(title || '').trim();
            var lines = [];

            if (cleanName) {
                if (language === 'en') {
                    lines.push(cleanName);
                } else if (/^(C\.|C\s|LIC\.|ING\.|MTRO\.|MTRA\.|DR\.|DRA\.)/i.test(cleanName)) {
                    lines.push(cleanName);
                } else {
                    lines.push('C. ' + cleanName);
                }
            }
            if (cleanTitle) {
                lines.push(cleanTitle);
            }
            return lines.join('\n');
        }

        function findAvisoProfile(recordId, profileId) {
            var profilesState = getAvisoProfilesState(recordId);
            var id = Number(profileId || 0);
            if (!profilesState || !Array.isArray(profilesState.profiles) || !id) {
                return null;
            }
            for (var index = 0; index < profilesState.profiles.length; index += 1) {
                if (Number(profilesState.profiles[index].id || 0) === id) {
                    return profilesState.profiles[index];
                }
            }
            return null;
        }

        function applyAvisoProfile(profile, recordId, options) {
            if (!profile || typeof profile !== 'object') {
                updateAvisoReadiness(recordId);
                return;
            }

            var opts = options && typeof options === 'object' ? options : {};
            $avisoRigNameInput.val(profile.rig_name || '');
            $avisoRigImoInput.val(profile.rig_imo || '');
            $avisoRigFieldInput.val(profile.rig_field || '');
            $avisoRigAreaInput.val(profile.rig_area || '');
            $avisoComitenteInput.val(profile.comitente || '');

            var recipient = resolveProfileContact(profile, 'recipient');
            var signer = resolveProfileContact(profile, 'signer');
            if (recipient.name || recipient.title) {
                $avisoRecipientInput.val(formatAvisoRecipientText(recipient.name, recipient.title));
            }
            if (signer.name) {
                $avisoSignerNameInput.val(signer.name);
            }
            if (signer.title) {
                $avisoSignerTitleInput.val(signer.title);
            }

            var state = getAvisoState(recordId);
            var manifest = state && state.details ? String(state.details.manifiesto || '').trim() : '';
            var prefix = String(profile.document_prefix || 'MADE').trim() || 'MADE';
            if (manifest && (!opts.preserveDocumentCode || !$avisoDocumentCodeInput.val())) {
                $avisoDocumentCodeInput.val(prefix + '-' + manifest);
            }

            if (state && canEditRecords && !opts.silent) {
                state.dirty = true;
            }
            updateAvisoReadiness(recordId);
        }

        function renderAvisoProfiles(recordId) {
            if (!$avisoProfileSelect.length) {
                return;
            }

            var profilesState = getAvisoProfilesState(recordId);
            var profiles = profilesState && Array.isArray(profilesState.profiles) ? profilesState.profiles : [];
            var state = getAvisoState(recordId);
            var detail = state && state.details && typeof state.details === 'object' ? state.details : {};
            var savedProfileId = Number(detail.profile_id || detail.aviso_profile_id || (profilesState && profilesState.savedProfileId) || 0);
            var hasSavedRig = Boolean(String(detail.rig_name || '').trim());
            var selectedId = savedProfileId || (!hasSavedRig ? Number(profilesState && profilesState.defaultProfileId || 0) : 0);

            $avisoProfileSelect.empty();
            $avisoProfileSelect.append(
                $('<option>').attr('value', '').text(language === 'en' ? 'Manual / no saved profile' : 'Manual / sin perfil guardado')
            );

            profiles.forEach(function (profile) {
                var scopeLabel = profile.scope === 'client'
                    ? (language === 'en' ? 'client' : 'cliente')
                    : (language === 'en' ? 'global' : 'global');
                var label = String(profile.name || '').trim() || ('#' + profile.id);
                label += ' · ' + scopeLabel;
                if (profile.is_default) {
                    label += language === 'en' ? ' · default' : ' · predeterminado';
                }
                $avisoProfileSelect.append(
                    $('<option>').attr('value', String(profile.id)).text(label)
                );
            });

            if (selectedId && findAvisoProfile(recordId, selectedId)) {
                $avisoProfileSelect.val(String(selectedId));
                if (!savedProfileId && !hasSavedRig) {
                    applyAvisoProfile(findAvisoProfile(recordId, selectedId), recordId, { silent: true });
                }
            } else {
                $avisoProfileSelect.val('');
            }

            $avisoProfileSelect.prop('disabled', !canEditRecords || avisoProfilesBusy);
            updateAvisoReadiness(recordId);
        }

        function loadAvisoProfiles(record) {
            if (!record || typeof record.id === 'undefined' || !avisoProfilesUrl) {
                return Promise.resolve(null);
            }

            var recordId = String(record.id);
            avisoProfilesBusy = true;
            if ($avisoProfileSelect.length) {
                $avisoProfileSelect.prop('disabled', true);
            }

            return fetch(avisoProfilesUrl + '?desembarque_id=' + encodeURIComponent(recordId), {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            }).then(function (response) {
                return response.text().then(function (text) {
                    var payload = {};
                    try {
                        payload = text ? JSON.parse(text) : {};
                    } catch (error) {
                        payload = {};
                    }
                    if (!response.ok || payload.success === false) {
                        throw new Error(payload.message || (language === 'en' ? 'Unable to load Rig profiles.' : 'No fue posible cargar los perfiles de Rig.'));
                    }
                    return payload;
                });
            }).then(function (payload) {
                avisoProfilesByRecord[recordId] = {
                    profiles: Array.isArray(payload.profiles) ? payload.profiles : [],
                    savedProfileId: payload.saved_profile_id || null,
                    defaultProfileId: payload.default_profile_id || null
                };
                renderAvisoProfiles(recordId);
                return avisoProfilesByRecord[recordId];
            }).catch(function (error) {
                if (window.console && typeof window.console.warn === 'function') {
                    console.warn('[aviso] profiles', error);
                }
                return null;
            }).finally(function () {
                avisoProfilesBusy = false;
                renderAvisoProfiles(recordId);
            });
        }

        function getAvisoReadinessIssues(recordId) {
            var issues = [];
            var state = getAvisoState(recordId);
            var items = state && Array.isArray(state.items) ? state.items : [];

            if (!items.length) {
                issues.push(language === 'en' ? 'structured Excel data' : 'datos estructurados del Excel');
            }
            if (items.length && findMissingAvisoImporters(items).length) {
                issues.push(language === 'en' ? 'importer legal business names' : 'razones sociales de importadores');
            }
            if (!String($avisoRigNameInput.val() || '').trim()) {
                issues.push(language === 'en' ? 'Rig name' : 'nombre del Rig');
            }
            if (!String($avisoRigImoInput.val() || '').trim()) {
                issues.push(language === 'en' ? 'Rig IMO' : 'IMO del Rig');
            }
            if (!String($avisoRigFieldInput.val() || '').trim()) {
                issues.push(language === 'en' ? 'field' : 'campo');
            }
            if (!String($avisoComitenteInput.val() || '').trim()) {
                issues.push(language === 'en' ? 'principal' : 'comitente');
            }
            return issues;
        }

        function updateAvisoReadiness(recordId) {
            if (!$avisoReadiness.length || !$avisoReadinessText.length) {
                return;
            }
            var issues = getAvisoReadinessIssues(recordId);
            $avisoReadiness.removeClass('alert-light alert-success alert-warning alert-danger');
            if (!issues.length) {
                $avisoReadiness.addClass('alert-success');
                $avisoReadinessText.text(language === 'en'
                    ? 'Ready. Source data, importers and Rig information are complete.'
                    : 'Listo. Los datos fuente, importadores e información del Rig están completos.');
            } else {
                $avisoReadiness.addClass('alert-warning');
                $avisoReadinessText.text((language === 'en' ? 'Missing: ' : 'Falta: ') + issues.join(', ') + '.');
            }
        }

        function saveCurrentAvisoProfile(record) {
            if (!record || typeof record.id === 'undefined' || !avisoProfilesUrl || !canEditRecords) {
                return Promise.resolve(null);
            }

            function requestName() {
                if (window.Swal && typeof window.Swal.fire === 'function') {
                    return window.Swal.fire({
                        title: language === 'en' ? 'Save Rig / project profile' : 'Guardar perfil Rig / Proyecto',
                        input: 'text',
                        inputLabel: language === 'en' ? 'Profile name' : 'Nombre del perfil',
                        inputPlaceholder: language === 'en' ? 'Example: Deepwater Thalassa · Trion' : 'Ejemplo: Deepwater Thalassa · Trion',
                        showCancelButton: true,
                        confirmButtonText: language === 'en' ? 'Save' : 'Guardar',
                        cancelButtonText: language === 'en' ? 'Cancel' : 'Cancelar',
                        inputValidator: function (value) {
                            return String(value || '').trim() ? undefined : (language === 'en' ? 'Enter a profile name.' : 'Captura un nombre para el perfil.');
                        }
                    }).then(function (result) {
                        return result && result.isConfirmed ? String(result.value || '').trim() : '';
                    });
                }
                return Promise.resolve(String(window.prompt(language === 'en' ? 'Profile name:' : 'Nombre del perfil:', '') || '').trim());
            }

            return requestName().then(function (profileName) {
                if (!profileName) {
                    return null;
                }

                var currentProfilesState = getAvisoProfilesState(String(record.id));
                var hasClientProfile = currentProfilesState && Array.isArray(currentProfilesState.profiles)
                    ? currentProfilesState.profiles.some(function (item) { return item && item.scope === 'client'; })
                    : false;
                var payload = {
                    csrf_token: csrfToken,
                    desembarque_id: String(record.id),
                    name: profileName,
                    scope: 'client',
                    is_default: !hasClientProfile,
                    rig_name: String($avisoRigNameInput.val() || '').trim(),
                    rig_imo: String($avisoRigImoInput.val() || '').trim(),
                    rig_field: String($avisoRigFieldInput.val() || '').trim(),
                    rig_area: String($avisoRigAreaInput.val() || '').trim(),
                    comitente: String($avisoComitenteInput.val() || '').trim(),
                    document_prefix: String($avisoDocumentCodeInput.val() || '').trim().split('-')[0] || 'MADE'
                };

                if (!payload.rig_name) {
                    throw new Error(language === 'en' ? 'Enter the Rig name before saving the profile.' : 'Captura el nombre del Rig antes de guardar el perfil.');
                }

                return fetch(avisoProfilesUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                }).then(function (response) {
                    return response.text().then(function (text) {
                        var result = {};
                        try { result = text ? JSON.parse(text) : {}; } catch (error) { result = {}; }
                        if (!response.ok || result.success === false) {
                            throw new Error(result.message || (language === 'en' ? 'Unable to save the profile.' : 'No fue posible guardar el perfil.'));
                        }
                        return result;
                    });
                }).then(function (result) {
                    return loadAvisoProfiles(record).then(function () {
                        if (result.profile && result.profile.id && $avisoProfileSelect.length) {
                            $avisoProfileSelect.val(String(result.profile.id));
                        }
                        return result;
                    });
                });
            });
        }

        function buildAvisoImporterGroups(items) {
            var groups = [];
            var map = {};

            (Array.isArray(items) ? items : []).forEach(function (item, index) {
                var clave = String(item && item.clave || '').trim().toUpperCase();
                var pedimento = String(item && item.num_pedimento || '').trim();
                var key = normalizeAvisoClaveKey(clave) + '|' + normalizeAvisoPedimentoKey(pedimento);

                if (!Object.prototype.hasOwnProperty.call(map, key)) {
                    map[key] = {
                        key: key,
                        clave: clave,
                        pedimento: pedimento,
                        importer: String(item && item.importer_name || '').trim(),
                        indexes: []
                    };
                    groups.push(map[key]);
                }

                map[key].indexes.push(index);
                if (!map[key].importer && item && item.importer_name) {
                    map[key].importer = String(item.importer_name).trim();
                }
            });

            return groups;
        }

        function buildAvisoItemsPreview(items) {
            var lines = [];

            (Array.isArray(items) ? items : []).forEach(function (item, index) {
                var quantity = item && item.cantidad !== null && typeof item.cantidad !== 'undefined' && item.cantidad !== ''
                    ? String(item.cantidad)
                    : 'N/A';
                var unit = String(item && item.unidad || '').trim();
                var clave = String(item && item.clave || '').trim();
                var pedimento = String(item && item.num_pedimento || '').trim();
                var description = String(item && item.descripcion || '').trim();
                var serial = String(item && item.serial_number || '').trim();
                var importer = String(item && item.importer_name || '').trim();
                var header = (index + 1) + '. ' + (clave || (language === 'en' ? 'NO CODE' : 'SIN CLAVE')) + (pedimento ? ' · ' + pedimento : '');
                var detail = '   ' + quantity + (unit ? ' ' + unit : '') + ' — ' + description;

                lines.push(header);
                lines.push(detail);
                if (serial) {
                    lines.push('   S/N: ' + serial);
                }
                if (importer) {
                    lines.push('   Importador: ' + importer);
                }
                lines.push('');
            });

            while (lines.length && lines[lines.length - 1] === '') {
                lines.pop();
            }

            return lines.join('\n');
        }

        function isAvisoImageAttachment(attachment) {
            if (!attachment || typeof attachment !== 'object') {
                return false;
            }
            var mime = String(attachment.mime_type || '').toLowerCase();
            var extension = String(attachment.extension || '').toLowerCase().replace(/^\./, '');
            return mime.indexOf('image/') === 0 && ['jpg', 'jpeg', 'png', 'gif'].indexOf(extension) !== -1;
        }

        function getAvisoPhotoPreviewUrl(fileId) {
            var id = Number(fileId || 0);
            if (!id) {
                return '';
            }
            return avisoImageUrl + '?file_id=' + encodeURIComponent(String(id));
        }

        function getAvisoImageAttachments(recordId) {
            return getRecordAttachments(recordId).filter(isAvisoImageAttachment);
        }

        function normalizeAvisoPhotos(photos) {
            var result = [];
            var seenFiles = {};
            (Array.isArray(photos) ? photos : []).forEach(function (photo, index) {
                if (!photo || typeof photo !== 'object') {
                    return;
                }
                var fileId = Number(photo.file_id || photo.id || 0);
                if (!fileId || seenFiles[fileId]) {
                    return;
                }
                seenFiles[fileId] = true;
                result.push({
                    id: Number(photo.id || 0) || null,
                    file_id: fileId,
                    aviso_item_id: Number(photo.aviso_item_id || 0) || null,
                    item_source_row: Number(photo.item_source_row || 0) || null,
                    item_description: String(photo.item_description || ''),
                    caption: String(photo.caption || ''),
                    sort_order: index + 1,
                    original_name: String(photo.original_name || ''),
                    mime_type: String(photo.mime_type || ''),
                    extension: String(photo.extension || ''),
                    size: Number(photo.size || 0) || 0,
                    preview_url: String(photo.preview_url || getAvisoPhotoPreviewUrl(fileId)),
                    download_url: String(photo.download_url || ('../api/desembarques/files/download.php?id=' + encodeURIComponent(String(fileId))))
                });
            });
            return result;
        }

        function findAvisoItemByPhotoReference(state, photo) {
            var items = state && Array.isArray(state.items) ? state.items : [];
            var sourceRow = Number(photo && photo.item_source_row || 0);
            var itemId = Number(photo && photo.aviso_item_id || 0);
            for (var index = 0; index < items.length; index += 1) {
                if (sourceRow && Number(items[index].source_row || 0) === sourceRow) {
                    return items[index];
                }
                if (!sourceRow && itemId && Number(items[index].id || 0) === itemId) {
                    return items[index];
                }
            }
            return null;
        }

        function buildAvisoPhotoItemLabel(item) {
            if (!item || typeof item !== 'object') {
                return '';
            }
            var prefix = [];
            if (item.clave) {
                prefix.push(String(item.clave));
            }
            if (item.num_pedimento) {
                prefix.push(String(item.num_pedimento));
            }
            var description = String(item.descripcion || '').trim();
            if (description.length > 90) {
                description = description.slice(0, 87) + '...';
            }
            return (prefix.length ? prefix.join(' · ') + ' — ' : '') + description;
        }

        function buildAvisoPhotoDefaultCaption(photo, state) {
            var item = findAvisoItemByPhotoReference(state, photo);
            if (item && String(item.descripcion || '').trim()) {
                return String(item.descripcion || '').trim();
            }
            var filename = String(photo && photo.original_name || '').trim();
            return filename.replace(/\.[a-z0-9]{2,5}$/i, '') || (language === 'en' ? 'Unloading photograph' : 'Fotografía del desembarque');
        }

        function clearAvisoPhotoFeedback() {
            if ($avisoPhotoFeedback.length) {
                $avisoPhotoFeedback.addClass('d-none').text('');
            }
        }

        function showAvisoPhotoFeedback(message) {
            if ($avisoPhotoFeedback.length) {
                $avisoPhotoFeedback.removeClass('d-none').text(String(message || ''));
            } else if (message) {
                showAvisoAlert(message);
            }
        }

        function markAvisoPhotoStateDirty(recordId) {
            var state = getAvisoState(recordId);
            if (state && canEditRecords) {
                state.dirty = true;
            }
        }

        function renderAvisoPhotos(recordId) {
            var state = getAvisoState(recordId);
            var photos = normalizeAvisoPhotos(state && state.photos);
            if (state) {
                state.photos = photos;
            }

            if ($avisoPhotosCount.length) {
                $avisoPhotosCount
                    .removeClass('text-bg-secondary text-bg-success text-bg-warning')
                    .addClass(photos.length ? (state && state.dirty ? 'text-bg-warning' : 'text-bg-success') : 'text-bg-secondary')
                    .text(photos.length ? String(photos.length) + (language === 'en' ? ' photos' : ' fotos') : '0');
            }

            if ($avisoPhotosSelected.length) {
                $avisoPhotosSelected.empty().toggleClass('d-none', photos.length === 0);
            }
            if ($avisoPhotosEmpty.length) {
                $avisoPhotosEmpty.toggleClass('d-none', photos.length > 0);
            }

            var items = state && Array.isArray(state.items) ? state.items : [];
            photos.forEach(function (photo, index) {
                var $column = $('<div>').addClass('col-12 col-lg-6').attr('data-aviso-photo-file-id', String(photo.file_id));
                var $card = $('<div>').addClass('card h-100');
                var $body = $('<div>').addClass('card-body');
                var $top = $('<div>').addClass('row g-3');
                var $imageCol = $('<div>').addClass('col-sm-4');
                var $image = $('<img>')
                    .attr('src', photo.preview_url || getAvisoPhotoPreviewUrl(photo.file_id))
                    .attr('alt', photo.caption || photo.original_name || (language === 'en' ? 'Unloading photograph' : 'Fotografía del desembarque'))
                    .attr('loading', 'lazy')
                    .addClass('img-fluid rounded border bg-light w-100')
                    .css({ height: '140px', objectFit: 'contain' });
                $imageCol.append($image);

                var $fieldsCol = $('<div>').addClass('col-sm-8');
                var $name = $('<div>').addClass('small fw-semibold text-break mb-2').text(photo.original_name || ('#' + photo.file_id));
                var $itemLabel = $('<label>').addClass('form-label small mb-1').text(language === 'en' ? 'Linked merchandise' : 'Mercancía relacionada');
                var $itemSelect = $('<select>')
                    .addClass('form-select form-select-sm mb-2')
                    .attr('data-aviso-photo-item', String(photo.file_id))
                    .prop('disabled', !canEditRecords);
                $itemSelect.append($('<option>').attr('value', '').text(language === 'en' ? 'General / unassigned' : 'General / sin asignar'));
                items.forEach(function (item) {
                    var sourceRow = Number(item.source_row || 0);
                    var itemId = Number(item.id || 0);
                    var value = sourceRow ? 'row:' + sourceRow : (itemId ? 'id:' + itemId : '');
                    if (!value) {
                        return;
                    }
                    var $option = $('<option>').attr('value', value).text(buildAvisoPhotoItemLabel(item));
                    if ((sourceRow && sourceRow === Number(photo.item_source_row || 0)) || (!sourceRow && itemId && itemId === Number(photo.aviso_item_id || 0))) {
                        $option.prop('selected', true);
                    }
                    $itemSelect.append($option);
                });

                var $captionLabel = $('<label>').addClass('form-label small mb-1').text(language === 'en' ? 'Caption' : 'Pie de foto');
                var $caption = $('<input>')
                    .attr('type', 'text')
                    .attr('maxlength', '255')
                    .attr('data-aviso-photo-caption', String(photo.file_id))
                    .addClass('form-control form-control-sm')
                    .val(photo.caption || '')
                    .attr('placeholder', buildAvisoPhotoDefaultCaption(photo, state))
                    .prop('readonly', !canEditRecords);

                $fieldsCol.append($name, $itemLabel, $itemSelect, $captionLabel, $caption);
                $top.append($imageCol, $fieldsCol);
                $body.append($top);

                if (canEditRecords) {
                    var $actions = $('<div>').addClass('d-flex flex-wrap gap-2 mt-3');
                    var $up = $('<button type="button">').addClass('btn btn-outline-secondary btn-sm').attr('data-action', 'aviso-photo-up').attr('data-file-id', String(photo.file_id)).text(language === 'en' ? 'Move up' : 'Subir');
                    var $down = $('<button type="button">').addClass('btn btn-outline-secondary btn-sm').attr('data-action', 'aviso-photo-down').attr('data-file-id', String(photo.file_id)).text(language === 'en' ? 'Move down' : 'Bajar');
                    var $remove = $('<button type="button">').addClass('btn btn-outline-danger btn-sm ms-auto').attr('data-action', 'aviso-photo-remove').attr('data-file-id', String(photo.file_id)).text(language === 'en' ? 'Remove from annex' : 'Quitar del anexo');
                    $up.prop('disabled', index === 0);
                    $down.prop('disabled', index === photos.length - 1);
                    $actions.append($up, $down, $remove);
                    $body.append($actions);
                }

                $card.append($body);
                $column.append($card);
                $avisoPhotosSelected.append($column);
            });

            var selectedFiles = {};
            photos.forEach(function (photo) { selectedFiles[Number(photo.file_id || 0)] = true; });
            var available = getAvisoImageAttachments(recordId).filter(function (attachment) {
                return !selectedFiles[Number(attachment.id || 0)];
            });

            if ($avisoPhotosAvailable.length) {
                $avisoPhotosAvailable.empty();
                available.forEach(function (attachment) {
                    var fileId = Number(attachment.id || 0);
                    var $column = $('<div>').addClass('col-12 col-md-6 col-xl-4');
                    var $card = $('<div>').addClass('border rounded-3 p-2 h-100 d-flex gap-2 align-items-center');
                    var $thumb = $('<img>')
                        .attr('src', getAvisoPhotoPreviewUrl(fileId))
                        .attr('alt', attachment.original_name || '')
                        .attr('loading', 'lazy')
                        .addClass('rounded border bg-light flex-shrink-0')
                        .css({ width: '72px', height: '72px', objectFit: 'cover' });
                    var $info = $('<div>').addClass('min-w-0 flex-grow-1');
                    $info.append($('<div>').addClass('small fw-semibold text-break').text(attachment.original_name || ('#' + fileId)));
                    if (attachment.size) {
                        $info.append($('<div>').addClass('small text-muted').text(formatFileSize(attachment.size)));
                    }
                    if (canEditRecords) {
                        $info.append(
                            $('<button type="button">')
                                .addClass('btn btn-outline-primary btn-sm mt-1')
                                .attr('data-action', 'aviso-photo-add')
                                .attr('data-file-id', String(fileId))
                                .text(language === 'en' ? 'Add to annex' : 'Agregar al anexo')
                        );
                    }
                    $card.append($thumb, $info);
                    $column.append($card);
                    $avisoPhotosAvailable.append($column);
                });
            }
            if ($avisoPhotosAvailableWrap.length) {
                $avisoPhotosAvailableWrap.toggleClass('d-none', available.length === 0);
            }
        }

        function formatAvisoVersionDateTime(value) {
            var raw = String(value || '').trim();
            if (!raw) {
                return '';
            }
            var parsed = new Date(raw.replace(' ', 'T'));
            if (Number.isNaN(parsed.getTime())) {
                return raw;
            }
            try {
                return new Intl.DateTimeFormat(language === 'en' ? 'en-US' : 'es-MX', {
                    day: '2-digit',
                    month: 'short',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                }).format(parsed);
            } catch (error) {
                return raw;
            }
        }

        function getAvisoVersions(recordId) {
            var key = String(recordId || '');
            return Object.prototype.hasOwnProperty.call(avisoVersionsByRecord, key)
                ? avisoVersionsByRecord[key]
                : [];
        }

        function renderAvisoVersions(recordId) {
            var versions = getAvisoVersions(recordId);
            if (!Array.isArray(versions)) {
                versions = [];
            }

            if ($avisoVersionsCount.length) {
                $avisoVersionsCount
                    .removeClass('text-bg-secondary text-bg-success')
                    .addClass(versions.length ? 'text-bg-success' : 'text-bg-secondary')
                    .text(String(versions.length));
            }
            if ($avisoVersionsStatus.length) {
                $avisoVersionsStatus
                    .removeClass('text-bg-light text-bg-success text-bg-danger text-secondary')
                    .addClass('text-bg-light border text-secondary')
                    .text(versions.length
                        ? (language === 'en' ? 'Immutable archive' : 'Archivo inmutable')
                        : (language === 'en' ? 'No issues yet' : 'Sin emisiones'));
            }
            if ($avisoVersionsEmpty.length) {
                $avisoVersionsEmpty.toggleClass('d-none', versions.length > 0);
            }
            if (!$avisoVersionsList.length) {
                return;
            }

            $avisoVersionsList.empty().toggleClass('d-none', versions.length === 0);
            versions.forEach(function (version) {
                var number = Number(version.version_no || 0);
                var title = (language === 'en' ? 'Version ' : 'Versión ') + (number || '?');
                var identity = String(version.document_code || version.notice_number || '').trim();
                if (identity) {
                    title += ' · ' + identity;
                }

                var generated = formatAvisoVersionDateTime(version.generated_at || '');
                var actor = String(version.generated_by_name || '').trim();
                var metaParts = [];
                if (generated) {
                    metaParts.push(generated);
                }
                if (actor) {
                    metaParts.push(actor);
                }
                if (Number(version.page_count || 0) > 0) {
                    metaParts.push(String(version.page_count) + (language === 'en' ? ' pages' : ' páginas'));
                }
                var versionItemCount = Number(version.item_count || 0);
                metaParts.push(String(versionItemCount) + (language === 'en' ? (versionItemCount === 1 ? ' merchandise line' : ' merchandise lines') : ' mercancías'));
                metaParts.push(String(Number(version.photo_count || 0)) + (language === 'en' ? ' photos' : ' fotos'));
                if (Number(version.size || 0) > 0) {
                    metaParts.push(formatFileSize(Number(version.size)));
                }

                var pdfHash = String(version.pdf_sha256 || '').trim();
                var hashLabel = pdfHash ? 'SHA-256 ' + pdfHash.slice(0, 16) + '…' : '';

                var $item = $('<div>').addClass('list-group-item');
                var $row = $('<div>').addClass('d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2');
                var $info = $('<div>').addClass('min-w-0');
                $info.append($('<div>').addClass('fw-semibold').text(title));
                if (metaParts.length) {
                    $info.append($('<div>').addClass('small text-muted').text(metaParts.join(' · ')));
                }
                if (hashLabel) {
                    $info.append($('<div>')
                        .addClass('small font-monospace text-muted text-break')
                        .attr('title', pdfHash)
                        .text(hashLabel));
                }

                var $actions = $('<div>').addClass('d-flex gap-2 flex-shrink-0');
                if (version.download_url) {
                    $actions.append(
                        $('<a>')
                            .addClass('btn btn-outline-primary btn-sm')
                            .attr('href', version.download_url)
                            .attr('target', '_blank')
                            .attr('rel', 'noopener noreferrer')
                            .text(language === 'en' ? 'Download PDF' : 'Descargar PDF')
                    );
                }
                $row.append($info, $actions);
                $item.append($row);
                $avisoVersionsList.append($item);
            });
        }

        function loadAvisoVersions(record) {
            if (!record || typeof record.id === 'undefined' || !avisoVersionsUrl) {
                return Promise.resolve([]);
            }
            var recordId = String(record.id);
            if ($avisoVersionsStatus.length) {
                $avisoVersionsStatus
                    .removeClass('text-bg-success text-bg-danger')
                    .addClass('text-bg-light border text-secondary')
                    .text(language === 'en' ? 'Loading' : 'Cargando');
            }

            return fetch(avisoVersionsUrl + '?desembarque_id=' + encodeURIComponent(recordId), {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            }).then(function (response) {
                return response.text().then(function (text) {
                    var payload = {};
                    try {
                        payload = text ? JSON.parse(text) : {};
                    } catch (error) {
                        payload = {};
                    }
                    if (!response.ok || payload.success === false) {
                        throw new Error(payload.message || (language === 'en' ? 'Unable to load PDF history.' : 'No fue posible cargar el historial de PDF.'));
                    }
                    return Array.isArray(payload.versions) ? payload.versions : [];
                });
            }).then(function (versions) {
                avisoVersionsByRecord[recordId] = versions;
                renderAvisoVersions(recordId);
                return versions;
            }).catch(function (error) {
                avisoVersionsByRecord[recordId] = [];
                renderAvisoVersions(recordId);
                if ($avisoVersionsStatus.length) {
                    $avisoVersionsStatus
                        .removeClass('text-bg-light text-bg-success text-secondary')
                        .addClass('text-bg-danger')
                        .text(language === 'en' ? 'History unavailable' : 'Historial no disponible');
                }
                if (window.console && typeof window.console.warn === 'function') {
                    console.warn('[aviso] versions', error);
                }
                return [];
            });
        }

        function archiveAvisoVersion(pdfResult, record) {
            if (!pdfResult || !pdfResult.blob || !record || typeof record.id === 'undefined') {
                return Promise.reject(new Error(language === 'en' ? 'There is no generated PDF to archive.' : 'No existe un PDF generado para archivar.'));
            }
            if (!avisoVersionsUrl) {
                return Promise.reject(new Error(language === 'en' ? 'The PDF history endpoint is not configured.' : 'No está configurado el endpoint del historial de PDF.'));
            }

            var recordId = String(record.id);
            var data = new FormData();
            data.append('csrf_token', csrfToken);
            data.append('desembarque_id', recordId);
            if (Number(pdfResult.pageCount || 0) > 0) {
                data.append('page_count', String(Number(pdfResult.pageCount)));
            }
            data.append('pdf', pdfResult.blob, String(pdfResult.filename || 'aviso-desembarque.pdf'));

            if ($avisoVersionsStatus.length) {
                $avisoVersionsStatus
                    .removeClass('text-bg-danger text-bg-success')
                    .addClass('text-bg-light border text-secondary')
                    .text(language === 'en' ? 'Archiving…' : 'Archivando…');
            }

            return fetch(avisoVersionsUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
                body: data
            }).then(function (response) {
                return response.text().then(function (text) {
                    var payload = {};
                    try {
                        payload = text ? JSON.parse(text) : {};
                    } catch (error) {
                        payload = {};
                    }
                    if (!response.ok || payload.success === false || !payload.version) {
                        throw new Error(payload.message || (language === 'en' ? 'Unable to archive the generated PDF.' : 'No fue posible archivar el PDF generado.'));
                    }
                    return payload;
                });
            }).then(function (payload) {
                var version = payload.version;
                var state = getAvisoState(recordId);
                if (state && payload.status && typeof payload.status === 'object') {
                    state.status = payload.status;
                }
                var versions = getAvisoVersions(recordId).slice();
                versions = versions.filter(function (candidate) {
                    return Number(candidate.id || 0) !== Number(version.id || 0);
                });
                versions.unshift(version);
                avisoVersionsByRecord[recordId] = versions;
                renderAvisoVersions(recordId);
                return version;
            });
        }

        function addAvisoPhotoFromAttachment(recordId, attachment) {
            var state = getAvisoState(recordId);
            if (!state || !attachment || !isAvisoImageAttachment(attachment)) {
                return false;
            }
            state.photos = normalizeAvisoPhotos(state.photos);
            var fileId = Number(attachment.id || 0);
            if (!fileId || state.photos.some(function (photo) { return Number(photo.file_id || 0) === fileId; })) {
                return false;
            }
            state.photos.push({
                id: null,
                file_id: fileId,
                aviso_item_id: null,
                item_source_row: null,
                caption: '',
                sort_order: state.photos.length + 1,
                original_name: String(attachment.original_name || ''),
                mime_type: String(attachment.mime_type || ''),
                extension: String(attachment.extension || ''),
                size: Number(attachment.size || 0) || 0,
                preview_url: getAvisoPhotoPreviewUrl(fileId),
                download_url: String(attachment.download_url || ('../api/desembarques/files/download.php?id=' + fileId))
            });
            markAvisoPhotoStateDirty(recordId);
            renderAvisoPhotos(recordId);
            return true;
        }

        function validateAvisoPhotoFiles(files) {
            var result = [];
            var allowed = ['jpg', 'jpeg', 'png', 'gif'];
            for (var index = 0; index < files.length; index += 1) {
                var file = files[index];
                var name = String(file && file.name || '');
                var extension = name.indexOf('.') !== -1 ? name.split('.').pop().toLowerCase() : '';
                var mime = String(file && file.type || '').toLowerCase();
                if (allowed.indexOf(extension) === -1 || (mime && mime.indexOf('image/') !== 0)) {
                    throw new Error(language === 'en' ? 'Only JPG, PNG or GIF images can be added to the photo annex.' : 'El anexo fotográfico sólo admite imágenes JPG, PNG o GIF.');
                }
                if (maxAttachmentSize > 0 && Number(file.size || 0) > maxAttachmentSize) {
                    throw new Error((language === 'en' ? 'A photograph exceeds the maximum size of ' : 'Una fotografía excede el tamaño máximo de ') + formatFileSize(maxAttachmentSize) + '.');
                }
                result.push(file);
            }
            return result;
        }

        function uploadAvisoPhotoBatch(recordId, files) {
            if (!uploadEndpoint) {
                return Promise.reject(new Error(language === 'en' ? 'The file upload endpoint is not configured.' : 'No está configurado el endpoint para subir archivos.'));
            }
            var data = new FormData();
            data.append('csrf_token', csrfToken);
            data.append('desembarque_id', recordId);
            files.forEach(function (file) { data.append('attachments[]', file, file.name); });

            return fetch(uploadEndpoint, {
                method: 'POST',
                credentials: 'same-origin',
                body: data,
                headers: { 'Accept': 'application/json' }
            }).then(function (response) {
                return response.text().then(function (text) {
                    var payload = {};
                    try { payload = text ? JSON.parse(text) : {}; } catch (error) { payload = {}; }
                    if (!response.ok || payload.success === false) {
                        throw new Error(payload.message || (language === 'en' ? 'Unable to upload the photographs.' : 'No fue posible subir las fotografías.'));
                    }
                    return Array.isArray(payload.attachments) ? payload.attachments : [];
                });
            });
        }

        function uploadAvisoPhotos(recordId, files) {
            var validFiles;
            try {
                validFiles = validateAvisoPhotoFiles(files);
            } catch (error) {
                return Promise.reject(error);
            }
            if (!validFiles.length) {
                return Promise.reject(new Error(language === 'en' ? 'Select at least one photograph.' : 'Selecciona al menos una fotografía.'));
            }

            var batchSize = maxAttachmentsPerRequest > 0 ? maxAttachmentsPerRequest : 10;
            var batches = [];
            for (var index = 0; index < validFiles.length; index += batchSize) {
                batches.push(validFiles.slice(index, index + batchSize));
            }

            var uploaded = [];
            var chain = Promise.resolve();
            batches.forEach(function (batch) {
                chain = chain.then(function () {
                    return uploadAvisoPhotoBatch(recordId, batch).then(function (attachments) {
                        uploaded = uploaded.concat(attachments);
                    });
                });
            });

            return chain.then(function () {
                var combined = getRecordAttachments(recordId).concat(uploaded);
                setRecordAttachments(recordId, combined);
                uploaded.forEach(function (attachment) { addAvisoPhotoFromAttachment(recordId, attachment); });
                renderRows(currentRecords);
                renderAvisoPhotos(recordId);
                return uploaded;
            });
        }

        function setAvisoExcelStatus(text, style) {
            if (!$avisoExcelStatus.length) {
                return;
            }

            $avisoExcelStatus
                .removeClass('text-bg-secondary text-bg-success text-bg-warning text-bg-danger text-bg-info')
                .addClass(style || 'text-bg-secondary')
                .text(text || '');
        }

        function openAvisoAdvancedOptions() {
            var element = document.getElementById('report-aviso-advanced');
            if (!element) {
                return;
            }
            if (window.bootstrap && window.bootstrap.Collapse && typeof window.bootstrap.Collapse.getOrCreateInstance === 'function') {
                window.bootstrap.Collapse.getOrCreateInstance(element, { toggle: false }).show();
                return;
            }
            element.classList.add('show');
        }

        function renderAvisoExcelState(recordId) {
            var state = getAvisoState(recordId);
            var details = state && state.details && typeof state.details === 'object' ? state.details : {};
            var items = state && Array.isArray(state.items) ? state.items : [];

            if (!items.length) {
                setAvisoExcelStatus(language === 'en' ? 'No structured data' : 'Sin datos estructurados', 'text-bg-secondary');
                if ($avisoExcelMeta.length) {
                    $avisoExcelMeta.text(language === 'en'
                        ? 'Legacy record: upload its Excel once to migrate it to the structured flow.'
                        : 'Registro legacy: carga su Excel una sola vez para migrarlo al flujo estructurado.');
                }
                $avisoExcelPreview.addClass('d-none');
                $avisoImportersWrap.addClass('d-none');
                $avisoImporters.empty();
                $avisoItemsInput.val('');
                openAvisoAdvancedOptions();
                updateAvisoReadiness(recordId);
                return;
            }

            setAvisoExcelStatus(
                (language === 'en' ? 'Excel ready · ' : 'Excel listo · ') + items.length + (language === 'en' ? (items.length === 1 ? ' merchandise line' : ' merchandise lines') : ' mercancías'),
                state && state.dirty ? 'text-bg-warning' : 'text-bg-success'
            );

            if ($avisoExcelMeta.length) {
                var meta = String(details.source_excel_name || '').trim();
                if (details.fecha_desembarque_eta) {
                    meta += (meta ? ' · ' : '') + String(details.fecha_desembarque_eta).replace(' 00:00:00', '');
                }
                $avisoExcelMeta.text(meta || (language === 'en' ? 'Saved unloading data.' : 'Datos del desembarque guardados.'));
            }

            $('[data-aviso-preview="manifest"]').text(details.manifiesto || 'N/A');
            $('[data-aviso-preview="transport"]').text(details.medio_transporte || 'N/A');
            $('[data-aviso-preview="imo"]').text(details.imo_transporte || 'N/A');
            $('[data-aviso-preview="items"]').text(String(items.length));
            $avisoExcelPreview.removeClass('d-none');
            $avisoItemsInput.val(buildAvisoItemsPreview(items));

            var groups = buildAvisoImporterGroups(items);
            $avisoImporters.empty();

            groups.forEach(function (group) {
                var labelText = (group.clave || (language === 'en' ? 'No key' : 'Sin clave'));
                if (group.pedimento) {
                    labelText += ' · ' + group.pedimento;
                }

                var $column = $('<div>').addClass('col-12 col-lg-6');
                var $label = $('<label>').addClass('form-label small mb-1').text(labelText);
                var $input = $('<input>')
                    .attr('type', 'text')
                    .attr('maxlength', '255')
                    .attr('data-aviso-importer-key', group.key)
                    .addClass('form-control form-control-sm')
                    .val(group.importer || '')
                    .prop('readonly', !canEditRecords);

                $column.append($label, $input);
                $avisoImporters.append($column);
            });

            $avisoImportersWrap.toggleClass('d-none', groups.length === 0);
            updateAvisoReadiness(recordId);
        }

        function applySavedAvisoToForm(detail, record) {
            if (!detail || typeof detail !== 'object') {
                return;
            }

            function setValue($field, value, fallback) {
                if (!$field || !$field.length) {
                    return;
                }
                if (value !== null && typeof value !== 'undefined' && String(value).trim() !== '') {
                    $field.val(String(value));
                } else if (typeof fallback !== 'undefined' && fallback !== null && String(fallback).trim() !== '') {
                    $field.val(String(fallback));
                }
            }

            setValue($avisoDocumentCodeInput, detail.document_code);
            setValue($avisoRigNameInput, detail.rig_name);
            setValue($avisoRigImoInput, detail.rig_imo);
            setValue($avisoRigFieldInput, detail.rig_field);
            setValue($avisoRigAreaInput, detail.rig_area);
            setValue($avisoComitenteInput, detail.comitente);
            setValue($avisoDocumentTitleInput, detail.document_title);
            setValue($avisoNoticeNumberInput, detail.notice_number, detail.manifiesto);
            setValue($avisoLocationDateInput, detail.location_date_text);
            setValue($avisoRecipientInput, detail.recipient_text);
            setValue($avisoIntroductionInput, detail.introduction);
            setValue($avisoBodyInput, detail.body);
            setValue($avisoOperationsInput, detail.operations);
            setValue($avisoDocumentationInput, detail.documentation);
            setValue($avisoClosingInput, detail.closing_text);
            setValue($avisoSignerNameInput, detail.signer_name);
            setValue($avisoSignerTitleInput, detail.signer_title);
            setValue($avisoFooterInput, detail.footer_text);

            if (record && detail.manifiesto) {
                record.manifiesto = detail.manifiesto;
            }
        }

        function applyExcelDetailsToForm(details) {
            var safeDetails = details && typeof details === 'object' ? details : {};
            var manifest = String(safeDetails.manifiesto || '').trim();

            if (manifest) {
                $avisoNoticeNumberInput.val(manifest);
                $avisoDocumentCodeInput.val('MADE-' + manifest);
            }

            if (safeDetails.fecha_embarque && window.AvisoPdfGenerator && typeof window.AvisoPdfGenerator.formatSpanishDate === 'function') {
                var dateText = window.AvisoPdfGenerator.formatSpanishDate(safeDetails.fecha_embarque, false);
                if (dateText) {
                    $avisoLocationDateInput.val('Tampico, Tamaulipas a ' + dateText + '.');
                }
            }
        }

        function loadSavedAviso(record) {
            if (!record || typeof record.id === 'undefined') {
                return Promise.resolve(null);
            }

            var recordId = String(record.id);
            var currentState = getAvisoState(recordId);
            if (currentState && currentState.dirty) {
                renderAvisoExcelState(recordId);
                return Promise.resolve(currentState);
            }

            if (!avisoLoadUrl) {
                return Promise.resolve(null);
            }

            return fetch(avisoLoadUrl + '?desembarque_id=' + encodeURIComponent(recordId), {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            }).then(function (response) {
                return response.text().then(function (text) {
                    var payload = {};
                    try {
                        payload = text ? JSON.parse(text) : {};
                    } catch (error) {
                        payload = {};
                    }
                    if (!response.ok || payload.success === false) {
                        throw new Error(payload.message || (language === 'en' ? 'Unable to load the saved notice.' : 'No fue posible cargar el aviso guardado.'));
                    }
                    return payload;
                });
            }).then(function (payload) {
                var detail = payload.aviso && typeof payload.aviso === 'object' ? payload.aviso : {};
                var savedItems = Array.isArray(payload.items) ? payload.items : [];
                if (window.AvisoPdfGenerator && typeof window.AvisoPdfGenerator.matchImporters === 'function') {
                    savedItems = window.AvisoPdfGenerator.matchImporters(savedItems, record.pedimento_headers || []);
                }

                var state = {
                    details: detail,
                    items: savedItems,
                    photos: normalizeAvisoPhotos(payload.photos || []),
                    status: payload.status && typeof payload.status === 'object' ? payload.status : null,
                    dirty: false,
                    loaded: true
                };
                avisoDataByRecord[recordId] = state;
                applyExcelDetailsToForm(detail);
                applySavedAvisoToForm(detail, record);
                renderAvisoExcelState(recordId);
                renderAvisoPhotos(recordId);
                return state;
            }).catch(function (error) {
                if (window.console && typeof window.console.warn === 'function') {
                    console.warn('[aviso] load', error);
                }
                showAvisoAlert(error && error.message ? error.message : (language === 'en' ? 'Unable to load the saved notice.' : 'No fue posible cargar el aviso guardado.'));
                return null;
            });
        }

        function handleAvisoExcelChange() {
            if (avisoExcelBusy) {
                return;
            }

            var recordId = $avisoRecordIdInput.length ? String($avisoRecordIdInput.val() || '').trim() : '';
            var record = recordId && Object.prototype.hasOwnProperty.call(recordsById, recordId) ? recordsById[recordId] : null;
            var file = $avisoExcelInput.length && $avisoExcelInput[0].files ? $avisoExcelInput[0].files[0] : null;

            if (!record || !file) {
                return;
            }
            if (!window.AvisoPdfGenerator || typeof window.AvisoPdfGenerator.parseExcel !== 'function') {
                showAvisoAlert(language === 'en' ? 'The Excel/PDF helper is not available.' : 'El módulo de Excel/PDF no está disponible.');
                return;
            }

            resetAvisoAlert();
            avisoExcelBusy = true;
            setAvisoExcelStatus(language === 'en' ? 'Reading Excel…' : 'Leyendo Excel…', 'text-bg-info');
            $avisoExcelInput.prop('disabled', true);

            window.AvisoPdfGenerator.parseExcel(file).then(function (parsed) {
                var items = parsed.items || [];
                if (typeof window.AvisoPdfGenerator.matchImporters === 'function') {
                    items = window.AvisoPdfGenerator.matchImporters(items, record.pedimento_headers || []);
                }

                var previousState = getAvisoState(recordId);
                avisoDataByRecord[recordId] = {
                    details: parsed.details || {},
                    items: items,
                    photos: normalizeAvisoPhotos(previousState && previousState.photos || []),
                    status: previousState && previousState.status ? previousState.status : null,
                    dirty: true,
                    loaded: true
                };

                applyExcelDetailsToForm(parsed.details || {});
                renderAvisoExcelState(recordId);
                renderAvisoPhotos(recordId);
            }).catch(function (error) {
                showAvisoAlert(error && error.message ? error.message : (language === 'en' ? 'Unable to read the Excel file.' : 'No fue posible leer el archivo Excel.'));
                setAvisoExcelStatus(language === 'en' ? 'Excel error' : 'Error de Excel', 'text-bg-danger');
            }).finally(function () {
                avisoExcelBusy = false;
                $avisoExcelInput.prop('disabled', !canEditRecords);
            });
        }

        function findMissingAvisoImporters(items) {
            return buildAvisoImporterGroups(items).filter(function (group) {
                return !String(group.importer || '').trim();
            });
        }

        function buildAvisoSavePayload(recordId, state, formData) {
            var details = state && state.details && typeof state.details === 'object' ? state.details : {};
            return {
                csrf_token: csrfToken,
                desembarque_id: String(recordId),
                details: {
                    manifiesto: details.manifiesto || '',
                    medio_transporte: details.medio_transporte || '',
                    imo_transporte: details.imo_transporte || '',
                    consignataria: details.consignataria || '',
                    fecha_embarque: details.fecha_embarque || '',
                    lugar_desembarque: details.lugar_desembarque || '',
                    fecha_desembarque_eta: details.fecha_desembarque_eta || '',
                    domicilio_almacenamiento: details.domicilio_almacenamiento || '',
                    domicilio_reparacion: details.domicilio_reparacion || '',
                    source_excel_name: details.source_excel_name || '',
                    source_excel_sha256: details.source_excel_sha256 || ''
                },
                document: {
                    profile_id: formData.profileId || '',
                    rig_name: formData.rigName,
                    rig_imo: formData.rigImo,
                    rig_field: formData.rigField,
                    rig_area: formData.rigArea,
                    comitente: formData.comitente,
                    document_code: formData.documentCode,
                    document_title: formData.documentTitle,
                    notice_number: formData.noticeNumber,
                    location_date_text: formData.locationDate,
                    recipient_text: formData.recipient,
                    introduction: formData.introduction,
                    body: formData.body,
                    operations: formData.operations,
                    documentation: formData.documentation,
                    closing_text: formData.closing,
                    signer_name: formData.signerName,
                    signer_title: formData.signerTitle,
                    footer_text: formData.footer
                },
                items: Array.isArray(state.items) ? state.items : [],
                photos: normalizeAvisoPhotos(state.photos || []).map(function (photo, index) {
                    return {
                        file_id: photo.file_id,
                        aviso_item_id: photo.aviso_item_id || null,
                        item_source_row: photo.item_source_row || null,
                        caption: String(photo.caption || '').trim(),
                        sort_order: index + 1
                    };
                })
            };
        }

        function saveAvisoData(formData, record) {
            var recordId = record && typeof record.id !== 'undefined' ? String(record.id) : '';
            var state = getAvisoState(recordId);

            if (!canEditRecords) {
                return Promise.resolve(state);
            }
            if (!state || !Array.isArray(state.items) || !state.items.length) {
                return Promise.reject(new Error(language === 'en' ? 'This record has no structured source data.' : 'Este registro no tiene datos fuente estructurados.'));
            }
            if (!avisoSaveUrl) {
                return Promise.reject(new Error(language === 'en' ? 'The notice save endpoint is not configured.' : 'No está configurado el endpoint para guardar el aviso.'));
            }

            var payload = buildAvisoSavePayload(recordId, state, formData);
            return fetch(avisoSaveUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            }).then(function (response) {
                return response.text().then(function (text) {
                    var result = {};
                    try {
                        result = text ? JSON.parse(text) : {};
                    } catch (error) {
                        result = {};
                    }
                    if (!response.ok || result.success === false) {
                        throw new Error(result.message || (language === 'en' ? 'Unable to save the notice.' : 'No fue posible guardar el aviso.'));
                    }
                    return result;
                });
            }).then(function (result) {
                state.dirty = false;
                state.details = state.details && typeof state.details === 'object' ? state.details : {};
                state.details.profile_id = formData.profileId || null;
                state.details.aviso_profile_id = formData.profileId || null;
                state.details.rig_name = formData.rigName || '';
                state.details.rig_imo = formData.rigImo || '';
                state.details.rig_field = formData.rigField || '';
                state.details.rig_area = formData.rigArea || '';
                state.details.comitente = formData.comitente || '';
                state.details.document_code = formData.documentCode || '';
                state.details.notice_number = formData.noticeNumber || '';
                state.details.location_date_text = formData.locationDate || '';
                if (Array.isArray(result.items) && result.items.length) {
                    state.items = result.items;
                }
                state.photos = normalizeAvisoPhotos(Array.isArray(result.photos) ? result.photos : state.photos || []);
                renderAvisoExcelState(recordId);
                renderAvisoProfiles(recordId);
                renderAvisoPhotos(recordId);
                return result;
            });
        }


        function generateAvisoPdf(formData, record) {
            var recordId = record && typeof record.id !== 'undefined' ? String(record.id) : '';
            var state = getAvisoState(recordId);

            if (!window.AvisoPdfGenerator || typeof window.AvisoPdfGenerator.generate !== 'function' || !window.PDFLib) {
                showAvisoAlert(avisoMissingLibraryMessage);
                return Promise.resolve(false);
            }
            if (!state || !Array.isArray(state.items) || !state.items.length) {
                showAvisoAlert(language === 'en' ? 'Upload the Excel before generating the notice.' : 'Carga el Excel antes de generar el aviso.');
                return Promise.resolve(false);
            }

            var avisoDocument = {
                rig_name: formData.rigName,
                rig_imo: formData.rigImo,
                rig_field: formData.rigField,
                rig_area: formData.rigArea,
                comitente: formData.comitente
            };

            return window.AvisoPdfGenerator.generate({
                templateUrl: avisoTemplateUrl,
                autoDownload: false,
                formData: formData,
                record: record,
                aviso: {
                    details: state.details || {},
                    document: avisoDocument,
                    items: state.items,
                    photos: normalizeAvisoPhotos(state.photos || [])
                }
            }).then(function (result) {
                return result && result.blob ? result : false;
            }).catch(function (error) {
                if (window.console && typeof window.console.error === 'function') {
                    console.error('[aviso] pdf', error);
                }
                showAvisoAlert(error && error.message ? error.message : (language === 'en' ? 'Unable to generate the PDF.' : 'No fue posible generar el PDF.'));
                return false;
            });
        }

        function openAvisoModal(record) {
            if (!record) {
                showAlert('warning', avisoMissingRecordMessage);
                return;
            }

            resetAvisoAlert();
            if ($avisoExcelInput.length) {
                $avisoExcelInput.val('').prop('disabled', !canEditRecords);
            }

            var defaults = fillAvisoForm(record);
            updateAvisoSummary(record, defaults);

            var recordId = typeof record.id !== 'undefined' ? String(record.id) : '';
            var existingState = getAvisoState(recordId);
            var statePromise;
            if (existingState) {
                applySavedAvisoToForm(existingState.details || {}, record);
                renderAvisoExcelState(recordId);
                renderAvisoPhotos(recordId);
                statePromise = Promise.resolve(existingState);
            } else {
                renderAvisoExcelState(recordId);
                renderAvisoPhotos(recordId);
                statePromise = loadSavedAviso(record);
            }

            loadAvisoVersions(record);
            statePromise.finally(function () {
                loadAvisoProfiles(record);
                updateAvisoReadiness(recordId);
            });

            if (avisoModal) {
                avisoModal.show();
            }
        }

        function renderEditAttachmentsList(recordId) {
            if (!$editAttachmentsList.length) {
                return;
            }

            var attachments = getRecordAttachments(recordId);

            $editAttachmentsList.empty();

            if (!attachments.length) {
                $editAttachmentsList.addClass('d-none');

                if ($editAttachmentsEmpty.length) {
                    $editAttachmentsEmpty.removeClass('d-none');
                }

                return;
            }

            $editAttachmentsList.removeClass('d-none');

            if ($editAttachmentsEmpty.length) {
                $editAttachmentsEmpty.addClass('d-none');
            }

            attachments.forEach(function (attachment) {
                var attachmentId = typeof attachment.id !== 'undefined' ? String(attachment.id) : '';
                var name = attachment.original_name || (language === 'en' ? 'File' : 'Archivo');
                var sizeLabel = attachment.size && attachment.size > 0 ? formatFileSize(attachment.size) : '';
                var $item = $('<li></li>')
                    .addClass('list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2')
                    .attr('data-attachment-id', attachmentId);

                var $info = $('<div></div>').addClass('flex-grow-1 text-break');
                $info.append($('<div></div>').text(name));

                if (sizeLabel) {
                    $info.append($('<div></div>').addClass('small text-muted').text(sizeLabel));
                }

                var $actions = $('<div></div>').addClass('d-flex flex-wrap gap-2');

                if (attachment.download_url) {
                    var $downloadButton = $('<a></a>')
                        .attr('href', attachment.download_url)
                        .attr('target', '_blank')
                        .attr('rel', 'noopener noreferrer')
                        .addClass('btn btn-outline-secondary btn-sm')
                        .text(attachmentsDownloadLabel);
                    $actions.append($downloadButton);
                }

                if (canEditRecords && attachmentId) {
                    var $deleteButton = $('<button type="button"></button>')
                        .addClass('btn btn-outline-danger btn-sm')
                        .attr('data-action', 'delete-attachment')
                        .attr('data-attachment-id', attachmentId)
                        .text(attachmentsDeleteLabel);
                    $actions.append($deleteButton);
                }

                $item.append($info).append($actions);
                $editAttachmentsList.append($item);
            });
        }

        function validateAttachmentsForUpload(files) {
            var validFiles = [];
            var errorMessage = '';

            if (!Array.isArray(files) || files.length === 0) {
                return { files: validFiles, error: errorMessage };
            }

            if (maxAttachmentsPerRequest > 0 && files.length > maxAttachmentsPerRequest) {
                return { files: [], error: attachmentsTooManyError };
            }

            files.forEach(function (file) {
                if (!file) {
                    return;
                }

                var extension = getFileExtension(file.name || '');

                if (!isExtensionAllowed(extension)) {
                    if (!errorMessage) {
                        errorMessage = attachmentsTypeError;
                    }
                    return;
                }

                if (maxAttachmentSize > 0 && file.size > maxAttachmentSize) {
                    if (!errorMessage) {
                        var sizeMessage = attachmentsSizeErrorTemplate.replace(/\{\{\s*max\s*\}\}/g, formatFileSize(maxAttachmentSize));
                        errorMessage = sizeMessage;
                    }
                    return;
                }

                validFiles.push(file);
            });

            return { files: validFiles, error: errorMessage };
        }

        function parseJsonFromText(text) {
            if (typeof text !== 'string') {
                return null;
            }

            var trimmed = text.trim();

            if (!trimmed) {
                return null;
            }

            try {
                return JSON.parse(trimmed);
            } catch (error) {
                // Continue with a more defensive parsing strategy below.
            }

            var firstBraceIndex = trimmed.indexOf('{');
            var lastBraceIndex = trimmed.lastIndexOf('}');

            if (firstBraceIndex !== -1 && lastBraceIndex !== -1 && lastBraceIndex > firstBraceIndex) {
                var potentialJson = trimmed.slice(firstBraceIndex, lastBraceIndex + 1);

                try {
                    return JSON.parse(potentialJson);
                } catch (nestedError) {
                    // Ignore and fall through to return null.
                }
            }

            return null;
        }

        function handleUploadResponse(recordId, response) {
            if (!response || typeof response !== 'object') {
                showEditFormAlert('warning', attachmentsUploadErrorTemplate);

                return false;
            }

            if (response.success) {
                var newAttachments = Array.isArray(response.attachments) ? response.attachments : [];
                var combined = getRecordAttachments(recordId).concat(newAttachments);

                setRecordAttachments(recordId, combined);
                resetEditAttachmentsInput();
                renderRows(currentRecords);

                var successMessage = response.message || attachmentsUploadSuccess;
                showEditFormAlert('success', successMessage);
                showAlert('success', successMessage);

                return true;
            }

            var message = response.message ? response.message : attachmentsUploadErrorTemplate;
            showEditFormAlert('warning', message);

            if (response.errors && response.errors.attachments) {
                showEditAttachmentsFeedback(response.errors.attachments);
            }

            return false;
        }

        function uploadAttachments(recordId, files) {
            if (!recordId || !files.length || !uploadEndpoint) {
                return;
            }

            if (isUploadingAttachments) {
                return;
            }

            isUploadingAttachments = true;
            clearEditAttachmentsFeedback();

            if ($editAttachmentsUploadButton.length) {
                $editAttachmentsUploadButton.prop('disabled', true);
            }

            if ($editAttachmentsInput.length) {
                $editAttachmentsInput.prop('disabled', true);
            }

            var formData = new FormData();
            formData.append('csrf_token', csrfToken);
            formData.append('desembarque_id', recordId);

            files.forEach(function (file) {
                formData.append('attachments[]', file, file.name);
            });

            $.ajax({
                url: uploadEndpoint,
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json'
            })
                .done(function (response) {
                    handleUploadResponse(recordId, response);
                })
                .fail(function (jqXHR, textStatus) {
                    var isSuccessfulStatus = jqXHR && jqXHR.status >= 200 && jqXHR.status < 300;
                    var parsedResponse = null;

                    if (jqXHR && jqXHR.responseJSON && typeof jqXHR.responseJSON === 'object') {
                        parsedResponse = jqXHR.responseJSON;
                    }

                    if (!parsedResponse && jqXHR && typeof jqXHR.responseText === 'string') {
                        parsedResponse = parseJsonFromText(jqXHR.responseText);
                    }

                    if ((textStatus === 'parsererror' || isSuccessfulStatus) && parsedResponse) {
                        handleUploadResponse(recordId, parsedResponse);

                        return;
                    }

                    var status = jqXHR ? jqXHR.status : 0;
                    var message = attachmentsUploadErrorTemplate;

                    if (status === 422) {
                        message = jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.message
                            ? jqXHR.responseJSON.message
                            : attachmentsValidationErrorTemplate;

                        if (jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.errors && jqXHR.responseJSON.errors.attachments) {
                            showEditAttachmentsFeedback(jqXHR.responseJSON.errors.attachments);
                        }
                    } else if (status === 401) {
                        message = translate('common.session_expired', 'Your session has expired. Please sign in again.');
                    } else if (status === 419) {
                        message = jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.message
                            ? jqXHR.responseJSON.message
                            : translate('common.csrf_token_invalid', 'The security token has expired. Please refresh the page and try again.');
                    } else if (jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.message) {
                        message = jqXHR.responseJSON.message;
                    }

                    showEditFormAlert(status === 422 || status === 419 ? 'warning' : 'danger', message);

                    if (status === 401) {
                        window.setTimeout(function () {
                            window.location.href = 'login.php?status=expired&lang=' + encodeURIComponent(language);
                        }, 1500);
                    }
                })
                .always(function () {
                    isUploadingAttachments = false;

                    if ($editAttachmentsUploadButton.length) {
                        $editAttachmentsUploadButton.prop('disabled', false);
                    }

                    if ($editAttachmentsInput.length) {
                        $editAttachmentsInput.prop('disabled', false);
                    }
                });
        }

        function uploadPendingAttachments() {
            if (!canEditRecords) {
                return;
            }

            clearEditAttachmentsFeedback();

            if (!currentEditRecordId) {
                showEditAttachmentsFeedback(attachmentsUploadMissingError);
                return;
            }

            if (!pendingEditAttachments.length) {
                showEditAttachmentsFeedback(attachmentsUploadMissingError);
                return;
            }

            uploadAttachments(currentEditRecordId, pendingEditAttachments.slice());
        }

        function handleEditAttachmentsChange(event) {
            if (!canEditRecords) {
                return;
            }

            clearEditAttachmentsFeedback();

            var files = [];

            if (event && event.target && event.target.files) {
                files = Array.prototype.slice.call(event.target.files);
            }

            if (!files.length) {
                pendingEditAttachments = [];
                return;
            }

            var validation = validateAttachmentsForUpload(files);

            pendingEditAttachments = validation.files;

            if (validation.error) {
                showEditAttachmentsFeedback(validation.error);
            }

            if (!pendingEditAttachments.length && !validation.error) {
                showEditAttachmentsFeedback(attachmentsUploadMissingError);
            }
        }

        function handleAttachmentDeletion(recordId, attachmentId, $trigger) {
            if (!recordId || !attachmentId || !deleteEndpoint) {
                return;
            }

            var proceedWithDeletion = function () {
                if ($trigger && $trigger.length) {
                    $trigger.prop('disabled', true);
                }

                $.ajax({
                    url: deleteEndpoint,
                    method: 'POST',
                    data: {
                        csrf_token: csrfToken,
                        id: attachmentId
                    },
                    dataType: 'json'
                })
                    .done(function (response) {
                        if (response && response.success) {
                            var remaining = getRecordAttachments(recordId).filter(function (attachment) {
                                return String(attachment.id) !== String(attachmentId);
                            });

                            setRecordAttachments(recordId, remaining);
                            renderRows(currentRecords);

                            var successMessage = response.message || attachmentsDeleteSuccess;
                            showEditFormAlert('success', successMessage);
                            showAlert('success', successMessage);
                        } else {
                            var message = response && response.message ? response.message : attachmentsDeleteErrorTemplate;
                            showEditFormAlert('warning', message);
                        }
                    })
                    .fail(function (jqXHR) {
                        var status = jqXHR ? jqXHR.status : 0;
                        var message = attachmentsDeleteErrorTemplate;

                        if (status === 401) {
                            message = translate('common.session_expired', 'Your session has expired. Please sign in again.');
                        } else if (status === 419) {
                            message = jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.message
                                ? jqXHR.responseJSON.message
                                : translate('common.csrf_token_invalid', 'The security token has expired. Please refresh the page and try again.');
                        } else if (jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.message) {
                            message = jqXHR.responseJSON.message;
                        }

                        showEditFormAlert(status === 419 ? 'warning' : 'danger', message);

                        if (status === 401) {
                            window.setTimeout(function () {
                                window.location.href = 'login.php?status=expired&lang=' + encodeURIComponent(language);
                            }, 1500);
                        }
                    })
                    .always(function () {
                        if ($trigger && $trigger.length) {
                            $trigger.prop('disabled', false);
                        }
                    });
            };

            if (window.Swal && typeof window.Swal.fire === 'function') {
                window.Swal.fire({
                    icon: 'warning',
                    text: attachmentsDeleteConfirm,
                    showCancelButton: true,
                    confirmButtonText: attachmentsDeleteConfirmButton,
                    cancelButtonText: attachmentsDeleteCancelButton
                }).then(function (result) {
                    if (result && result.isConfirmed) {
                        proceedWithDeletion();
                    }
                });
                return;
            }

            if (window.confirm(attachmentsDeleteConfirm)) {
                proceedWithDeletion();
            }
        }

        function clearObservacionesAlert() {
            if (!$observacionesAlert.length) {
                return;
            }

            $observacionesAlert.removeClass('alert-success alert-danger alert-warning alert-info');
            $observacionesAlert.addClass('d-none').text('');
        }

        function showObservacionesAlert(type, message) {
            if (!$observacionesAlert.length) {
                return;
            }

            $observacionesAlert.removeClass('alert-success alert-danger alert-warning alert-info');
            $observacionesAlert.addClass('alert-' + type).removeClass('d-none').text(message);
        }

        function clearObservacionesErrors() {
            if (!$observacionesForm.length) {
                return;
            }

            $observacionesForm.find('.is-invalid').removeClass('is-invalid');
            $observacionesForm.find('.invalid-feedback[data-feedback-for]').text('');
        }

        function displayObservacionesErrors(errors) {
            if (!errors || !$observacionesForm.length) {
                return;
            }

            Object.keys(errors).forEach(function (field) {
                if (!Object.prototype.hasOwnProperty.call(errors, field)) {
                    return;
                }

                var message = errors[field];

                if (message === undefined || message === null) {
                    message = '';
                }

                var $field = $observacionesForm.find('[name="' + field + '"]');
                var $feedback = $observacionesForm.find('.invalid-feedback[data-feedback-for="' + field + '"]');

                if ($field.length) {
                    $field.addClass('is-invalid');
                }

                if ($feedback.length) {
                    $feedback.text(String(message));
                }
            });
        }

        function clearObservationEditingState() {
            currentObservationEditId = null;

            if ($observacionesObservationId.length) {
                $observacionesObservationId.val('');
            }

            if ($observacionesSaveButton.length) {
                if (observationsSaveLabel) {
                    $observacionesSaveButton.text(observationsSaveLabel);
                }

                $observacionesSaveButton.removeAttr('data-editing');
            }

            var normalizedRecordId = String(currentObservacionesRecordId || '');

            if (normalizedRecordId && Array.isArray(observacionesCache[normalizedRecordId])) {
                renderObservacionesList(normalizedRecordId, observacionesCache[normalizedRecordId]);
            }
        }

        function findObservationInCache(recordId, observationId) {
            var normalizedRecordId = String(recordId || '');
            var normalizedObservationId = String(observationId || '');

            if (!normalizedRecordId || !normalizedObservationId) {
                return null;
            }

            var entries = observacionesCache[normalizedRecordId];

            if (!Array.isArray(entries)) {
                return null;
            }

            for (var index = 0; index < entries.length; index += 1) {
                var entry = entries[index];

                if (!entry) {
                    continue;
                }

                var entryId = typeof entry.id !== 'undefined' && entry.id !== null
                    ? String(entry.id)
                    : '';

                if (entryId && entryId === normalizedObservationId) {
                    return entry;
                }
            }

            return null;
        }

        function setObservationEditingState(recordId, observation) {
            if (!observation) {
                return;
            }

            var observationId = typeof observation.id !== 'undefined' && observation.id !== null
                ? String(observation.id)
                : '';

            if (!observationId) {
                return;
            }

            currentObservationEditId = observationId;

            if ($observacionesObservationId.length) {
                $observacionesObservationId.val(observationId);
            }

            if ($observacionesTextarea.length) {
                $observacionesTextarea.val(observation.message || '');
                $observacionesTextarea.trigger('input');
                $observacionesTextarea.focus();
            }

            if ($observacionesSaveButton.length) {
                if (observationEditLabel) {
                    $observacionesSaveButton.text(observationEditLabel);
                }

                $observacionesSaveButton.attr('data-editing', '1');
            }

            if (currentObservacionesRecordId === String(recordId || '')) {
                renderObservacionesList(recordId, observacionesCache[String(recordId || '')] || []);
            }
        }

        function normalizeObservationCount(value) {
            var count = Number(value);

            if (!Number.isFinite(count) || count < 0) {
                return 0;
            }

            return Math.floor(count);
        }

        function getObservacionesState(recordId) {
            if (!recordId) {
                return null;
            }

            var key = String(recordId);

            if (!Object.prototype.hasOwnProperty.call(observacionesState, key)) {
                observacionesState[key] = {
                    count: 0,
                    hasUnread: false,
                    lastCreatedAt: '',
                    lastCreatedAtDisplay: '',
                    referencia: ''
                };
            }

            return observacionesState[key];
        }

        function updateObservacionesState(recordId, updates) {
            if (!recordId || !updates || typeof updates !== 'object') {
                return;
            }

            var state = getObservacionesState(recordId);

            if (!state) {
                return;
            }

            if (Object.prototype.hasOwnProperty.call(updates, 'count') && updates.count !== undefined) {
                state.count = normalizeObservationCount(updates.count);
            }

            if (Object.prototype.hasOwnProperty.call(updates, 'hasUnread') && updates.hasUnread !== undefined) {
                state.hasUnread = Boolean(updates.hasUnread);
            }

            if (Object.prototype.hasOwnProperty.call(updates, 'lastCreatedAt') && updates.lastCreatedAt !== undefined) {
                state.lastCreatedAt = String(updates.lastCreatedAt || '');
            }

            if (Object.prototype.hasOwnProperty.call(updates, 'lastCreatedAtDisplay') && updates.lastCreatedAtDisplay !== undefined) {
                state.lastCreatedAtDisplay = String(updates.lastCreatedAtDisplay || '');
            }

            if (Object.prototype.hasOwnProperty.call(updates, 'referencia') && updates.referencia !== undefined) {
                state.referencia = String(updates.referencia || '');
            }
        }

        function setRecordUnreadState(recordId, hasUnread) {
            if (!recordId) {
                return;
            }

            var normalizedValue = Boolean(hasUnread);

            if (Object.prototype.hasOwnProperty.call(recordsById, recordId) && recordsById[recordId]) {
                recordsById[recordId].has_unread_observaciones = normalizedValue;
            }

            if (Array.isArray(currentRecords)) {
                currentRecords.forEach(function (record) {
                    if (!record) {
                        return;
                    }

                    var idValue = typeof record.id !== 'undefined' ? String(record.id) : '';

                    if (idValue === recordId) {
                        record.has_unread_observaciones = normalizedValue;
                    }
                });
            }
        }

        function collectUnreadObservacionesFromState() {
            var unreadRecords = [];

            Object.keys(observacionesState).forEach(function (recordId) {
                if (!Object.prototype.hasOwnProperty.call(observacionesState, recordId)) {
                    return;
                }

                var state = observacionesState[recordId];

                if (!state || !state.hasUnread) {
                    return;
                }

                var referencia = state.referencia || '';

                if (!referencia && Object.prototype.hasOwnProperty.call(recordsById, recordId) && recordsById[recordId]) {
                    referencia = recordsById[recordId].referencia || '';
                }

                unreadRecords.push({
                    id: recordId,
                    referencia: referencia,
                    last_created_at_display: state.lastCreatedAtDisplay || ''
                });
            });

            return unreadRecords;
        }

        function buildObservacionesAlertMessage(records, baseKey, fallback) {
            var message = translate(baseKey, fallback);
            var references = [];

            if (Array.isArray(records)) {
                records.forEach(function (record) {
                    if (!record || !record.referencia) {
                        return;
                    }

                    var reference = String(record.referencia).trim();

                    if (reference) {
                        references.push(reference);
                    }
                });
            }

            if (references.length > 0) {
                var summary = references.slice(0, 3).join(', ');

                if (references.length > 3) {
                    summary += ' +' + (references.length - 3);
                }

                var fallbackWithList = message + ' ' + summary;
                message = translate(baseKey + '_with_list', fallbackWithList, { references: summary });
            }

            return message;
        }

        function showUnreadObservacionesAlert(records, isNew) {
            var baseKey = isNew
                ? 'reports.alert.observaciones_new_received'
                : 'reports.alert.observaciones_unread';
            var fallback = isNew
                ? 'A new observation was received.'
                : 'You have unread observations.';
            var message = buildObservacionesAlertMessage(records, baseKey, fallback);
            var title = translate('reports.observations_modal.title', 'Observations');
            var confirmLabel = translate('reports.observations_modal.cancel', 'Close');

            if (window.Swal && typeof window.Swal.fire === 'function') {
                window.Swal.fire({
                    icon: 'info',
                    title: title,
                    text: message,
                    confirmButtonText: confirmLabel,
                    timer: isNew ? 6000 : undefined,
                    timerProgressBar: Boolean(isNew)
                });
            } else {
                showAlert('info', message);
            }
        }

        function handleUnreadObservacionesChange(unreadRecords, options) {
            options = options || {};

            var unreadIds = {};

            unreadRecords.forEach(function (record) {
                if (!record || !record.id) {
                    return;
                }

                unreadIds[record.id] = true;
            });

            var previousUnreadIds = lastUnreadRecordIds;
            var previousIds = Object.keys(previousUnreadIds);
            var hasPreviousUnread = previousIds.length > 0;
            var hasUnread = unreadRecords.length > 0;
            var hasNewUnread = unreadRecords.some(function (record) {
                return record && record.id && !previousUnreadIds[record.id];
            });

            lastUnreadRecordIds = unreadIds;

            if (!hasUnread) {
                return;
            }

            if (options.force || !hasPreviousUnread || hasNewUnread || options.isNew) {
                showUnreadObservacionesAlert(unreadRecords, Boolean(options.isNew));
            }
        }

        function updateUnreadObservacionesState(options) {
            var unreadRecords = collectUnreadObservacionesFromState();
            handleUnreadObservacionesChange(unreadRecords, options || {});
        }

        function syncObservacionesStateFromRecord(record) {
            if (!record) {
                return;
            }

            var recordId = typeof record.id !== 'undefined' ? String(record.id) : '';

            if (!recordId) {
                return;
            }

            var count = normalizeObservationCount(record.observaciones_count);
            var lastCreatedAt = (record.observaciones_last_created_at || '').trim();
            var lastCreatedAtDisplay = (record.observaciones_last_created_at_display || '').trim();
            var hasUnread = Boolean(record.has_unread_observaciones);
            var referencia = record.referencia || '';

            updateObservacionesState(recordId, {
                count: count,
                lastCreatedAt: lastCreatedAt,
                lastCreatedAtDisplay: lastCreatedAtDisplay,
                hasUnread: hasUnread,
                referencia: referencia
            });

            setRecordUnreadState(recordId, hasUnread);
        }

        function markObservacionesAsRead(recordId, meta) {
            if (!recordId) {
                return;
            }

            var state = getObservacionesState(recordId);
            var updates = {
                hasUnread: false
            };

            if (meta && typeof meta === 'object') {
                if (Object.prototype.hasOwnProperty.call(meta, 'lastCreatedAt') && meta.lastCreatedAt !== undefined) {
                    updates.lastCreatedAt = meta.lastCreatedAt;
                }

                if (Object.prototype.hasOwnProperty.call(meta, 'lastCreatedAtDisplay') && meta.lastCreatedAtDisplay !== undefined) {
                    updates.lastCreatedAtDisplay = meta.lastCreatedAtDisplay;
                }

                if (Object.prototype.hasOwnProperty.call(meta, 'referencia') && meta.referencia !== undefined) {
                    updates.referencia = meta.referencia;
                }
            }

            updateObservacionesState(recordId, updates);
            setRecordUnreadState(recordId, false);
            updateObservationButton(recordId, state ? state.count : 0);
            updateUnreadObservacionesState({});
            refreshUnreadObservacionesStreamSoon();
        }

        function isDocumentVisible() {
            if (typeof document === 'undefined') {
                return true;
            }

            if (typeof document.visibilityState === 'string') {
                return document.visibilityState === 'visible';
            }

            return true;
        }

        function stopUnreadObservacionesPolling() {
            if (unreadObservacionesIntervalId !== null) {
                window.clearInterval(unreadObservacionesIntervalId);
                unreadObservacionesIntervalId = null;
            }

            if (unreadObservacionesImmediateTimeoutId !== null) {
                window.clearTimeout(unreadObservacionesImmediateTimeoutId);
                unreadObservacionesImmediateTimeoutId = null;
            }
        }

        function isUnreadObservacionesStreamSupported() {
            return unreadObservacionesStreamSupports;
        }

        function isUnreadObservacionesStreamActive() {
            return unreadObservacionesStream !== null;
        }

        function isUnreadObservacionesStreamHealthy() {
            return unreadObservacionesStreamHealthy;
        }

        function setUnreadObservacionesStreamHealthy(isHealthy) {
            var normalized = Boolean(isHealthy);

            if (unreadObservacionesStreamHealthy === normalized) {
                return;
            }

            unreadObservacionesStreamHealthy = normalized;

            if (normalized) {
                stopUnreadObservacionesPolling();
            } else if (isUnreadObservacionesStreamSupported()) {
                scheduleUnreadObservacionesCheck({ immediate: true, delay: 0 });
            }
        }

        function shouldUseUnreadObservacionesPolling() {
            return !isUnreadObservacionesStreamSupported() || !isUnreadObservacionesStreamHealthy();
        }

        function stopUnreadObservacionesStream(options) {
            options = options || {};

            if (unreadObservacionesStreamReconnectTimeoutId !== null) {
                window.clearTimeout(unreadObservacionesStreamReconnectTimeoutId);
                unreadObservacionesStreamReconnectTimeoutId = null;
            }

            if (unreadObservacionesStream) {
                unreadObservacionesStream.close();
            }

            unreadObservacionesStream = null;
            unreadObservacionesStreamLastEventAt = 0;

            if (!options || options.silent !== true) {
                setUnreadObservacionesStreamHealthy(false);
            }
        }

        function scheduleUnreadObservacionesStreamReconnect(options) {
            options = options || {};

            if (!isUnreadObservacionesStreamSupported()) {
                return;
            }

            var delay = typeof options.delay === 'number' && options.delay >= 0
                ? options.delay
                : unreadObservacionesStreamRetryDelay;

            if (!isDocumentVisible()) {
                delay = Math.max(delay, unreadObservacionesPollDelay);
            }

            if (unreadObservacionesStreamReconnectTimeoutId !== null) {
                window.clearTimeout(unreadObservacionesStreamReconnectTimeoutId);
            }

            unreadObservacionesStreamReconnectTimeoutId = window.setTimeout(function () {
                unreadObservacionesStreamReconnectTimeoutId = null;

                if (options.requireVisibility === true && !isDocumentVisible()) {
                    scheduleUnreadObservacionesStreamReconnect({
                        delay: Math.max(unreadObservacionesPollDelay, unreadObservacionesStreamRetryDelay),
                        requireVisibility: true
                    });

                    return;
                }

                stopUnreadObservacionesStream({ silent: true });
                startUnreadObservacionesStream();
            }, Math.max(0, delay));
        }

        function scheduleUnreadObservacionesCheck(options) {
            options = options || {};

            if (!shouldUseUnreadObservacionesPolling()) {
                return;
            }

            var intervalCreated = false;

            if (unreadObservacionesIntervalId === null) {
                unreadObservacionesIntervalId = window.setInterval(checkUnreadObservaciones, unreadObservacionesPollDelay);
                intervalCreated = true;
            }

            var shouldScheduleImmediate = Boolean(options.immediate || intervalCreated);

            if (!shouldScheduleImmediate) {
                return;
            }

            var delay;

            if (typeof options.delay === 'number' && options.delay >= 0) {
                delay = options.delay;
            } else if (options.immediate === true) {
                delay = 0;
            } else {
                delay = Math.min(unreadObservacionesImmediateCheckDelay, unreadObservacionesPollDelay);
            }

            if (!isDocumentVisible() && options.immediate !== true) {
                delay = Math.max(delay, unreadObservacionesPollDelay);
            }

            if (unreadObservacionesImmediateTimeoutId !== null) {
                window.clearTimeout(unreadObservacionesImmediateTimeoutId);
            }

            unreadObservacionesImmediateTimeoutId = window.setTimeout(function () {
                unreadObservacionesImmediateTimeoutId = null;
                checkUnreadObservaciones();
            }, Math.max(0, delay));
        }

        function applyUnreadObservacionesItems(items, options) {
            options = options || {};

            var normalizedItems = Array.isArray(items) ? items : [];
            var unreadIdsMap = {};
            var hasNewUnread = false;

            normalizedItems.forEach(function (item) {
                if (!item) {
                    return;
                }

                var recordId = typeof item.id !== 'undefined' ? String(item.id) : '';

                if (!recordId) {
                    return;
                }

                unreadIdsMap[recordId] = true;

                var state = getObservacionesState(recordId);
                var wasUnread = state ? Boolean(state.hasUnread) : false;
                var countValue = typeof item.count !== 'undefined' ? item.count : undefined;
                var lastCreatedAt = (item.last_created_at || '').trim();
                var lastCreatedAtDisplay = (item.last_created_at_display || '').trim();
                var referencia = item.referencia || '';

                updateObservacionesState(recordId, {
                    count: countValue !== undefined ? countValue : state ? state.count : 0,
                    lastCreatedAt: lastCreatedAt || (state ? state.lastCreatedAt : ''),
                    lastCreatedAtDisplay: lastCreatedAtDisplay || (state ? state.lastCreatedAtDisplay : ''),
                    referencia: referencia || (state ? state.referencia : ''),
                    hasUnread: true
                });

                setRecordUnreadState(recordId, true);

                if (!wasUnread) {
                    hasNewUnread = true;
                }
            });

            Object.keys(observacionesState).forEach(function (recordId) {
                if (!Object.prototype.hasOwnProperty.call(observacionesState, recordId)) {
                    return;
                }

                if (unreadIdsMap[recordId]) {
                    return;
                }

                var state = observacionesState[recordId];

                if (state && state.hasUnread) {
                    updateObservacionesState(recordId, { hasUnread: false });
                    setRecordUnreadState(recordId, false);
                }
            });

            Object.keys(observacionesState).forEach(function (recordId) {
                var state = observacionesState[recordId];
                updateObservationButton(recordId, state ? state.count : 0);
            });

            var updateOptions = {
                isNew: (options && options.isNew === true) || hasNewUnread
            };

            if (options && options.force) {
                updateOptions.force = true;
            }

            updateUnreadObservacionesState(updateOptions);

            return {
                hasNewUnread: hasNewUnread,
                hasUnread: normalizedItems.length > 0,
                unreadIds: unreadIdsMap
            };
        }

        function getUnreadObservacionesStreamUrl() {
            var baseUrl = '../api/desembarques/observaciones_stream.php';
            var separator = baseUrl.indexOf('?') !== -1 ? '&' : '?';

            return baseUrl + separator + 'ts=' + Date.now();
        }

        function startUnreadObservacionesStream() {
            if (!isUnreadObservacionesStreamSupported()) {
                return;
            }

            if (isUnreadObservacionesStreamActive()) {
                return;
            }

            try {
                var streamUrl = getUnreadObservacionesStreamUrl();
                var eventSource = new window.EventSource(streamUrl, { withCredentials: true });

                eventSource.onopen = function () {
                    unreadObservacionesStreamLastEventAt = Date.now();
                    setUnreadObservacionesStreamHealthy(true);
                };

                eventSource.addEventListener('unread', function (event) {
                    unreadObservacionesStreamLastEventAt = Date.now();
                    setUnreadObservacionesStreamHealthy(true);

                    var payload = {};

                    if (event && typeof event.data === 'string' && event.data !== '') {
                        try {
                            payload = JSON.parse(event.data);
                        } catch (parseError) {
                            return;
                        }
                    }

                    handleUnreadObservacionesStreamData(payload || {});
                });

                eventSource.addEventListener('heartbeat', function () {
                    unreadObservacionesStreamLastEventAt = Date.now();
                });

                eventSource.addEventListener('error', function () {
                    unreadObservacionesStreamLastEventAt = Date.now();

                    stopUnreadObservacionesStream({ silent: true });
                    setUnreadObservacionesStreamHealthy(false);
                    scheduleUnreadObservacionesStreamReconnect({});
                });

                unreadObservacionesStream = eventSource;
            } catch (error) {
                stopUnreadObservacionesStream({ silent: true });
                setUnreadObservacionesStreamHealthy(false);
                scheduleUnreadObservacionesStreamReconnect({});
            }
        }

        function handleUnreadObservacionesStreamData(payload) {
            if (!payload || typeof payload !== 'object') {
                return;
            }

            var items = Array.isArray(payload.items) ? payload.items : [];
            var result = applyUnreadObservacionesItems(items, {
                isNew: Boolean(payload.is_new),
                force: Boolean(payload.force)
            });

            if (result.hasNewUnread) {
                unreadAlertOptions = { force: true, isNew: true };

                if (!isLoading) {
                    fetchReports();
                } else {
                    pendingFetchAfterLoad = true;
                }
            } else if (!payload.has_unread) {
                unreadAlertOptions = null;
            }
        }

        function refreshUnreadObservacionesStreamSoon() {
            if (!isUnreadObservacionesStreamSupported()) {
                return;
            }

            if (!isUnreadObservacionesStreamActive()) {
                return;
            }

            if (unreadObservacionesStreamRefreshTimeoutId !== null) {
                return;
            }

            unreadObservacionesStreamRefreshTimeoutId = window.setTimeout(function () {
                unreadObservacionesStreamRefreshTimeoutId = null;

                if (!isDocumentVisible()) {
                    return;
                }

                stopUnreadObservacionesStream({ silent: true });
                setUnreadObservacionesStreamHealthy(false);
                startUnreadObservacionesStream();
            }, 500);
        }

        function checkUnreadObservaciones() {
            if (isCheckingUnreadObservaciones) {
                var retryDelay = Math.min(
                    unreadObservacionesPollDelay,
                    Math.max(1000, Math.floor(unreadObservacionesPollDelay / 3))
                );

                scheduleUnreadObservacionesCheck({
                    immediate: true,
                    delay: retryDelay
                });

                return;
            }

            if (!shouldUseUnreadObservacionesPolling()) {
                return;
            }

            isCheckingUnreadObservaciones = true;

            $.ajax({
                url: '../api/desembarques/unread_observaciones.php',
                method: 'GET',
                dataType: 'json'
            })
                .done(function (response) {
                    if (!response || !response.success) {
                        return;
                    }

                    var items = Array.isArray(response.items) ? response.items : [];
                    var result = applyUnreadObservacionesItems(items, {});

                    if (result.hasNewUnread) {
                        unreadAlertOptions = { force: true, isNew: true };

                        if (!isLoading) {
                            fetchReports();
                        } else {
                            pendingFetchAfterLoad = true;
                        }
                    }
                })
                .always(function () {
                    isCheckingUnreadObservaciones = false;
                });
        }

        function formatObservationMessage(value) {
            var sanitized = escapeHtml(value || '');

            if (!sanitized) {
                return '';
            }

            return sanitized.replace(/\r\n|\r|\n/g, '<br>');
        }

        function resolveObservationAuthor(entry) {
            if (!entry) {
                return '';
            }

            if (entry.is_author) {
                return observationsYouLabel || ''; // Prefer localized label for current user
            }

            if (entry.author_display) {
                return entry.author_display;
            }

            if (entry.author_name) {
                return entry.author_name;
            }

            if (entry.author_email) {
                return entry.author_email;
            }

            if (entry.is_legacy && observationsLegacyAuthor) {
                return observationsLegacyAuthor;
            }

            return '';
        }

        function buildObservationMeta(entry) {
            if (!entry) {
                return '';
            }

            var meta = entry.meta || '';

            if (meta) {
                return meta;
            }

            var author = resolveObservationAuthor(entry);
            var dateDisplay = entry.created_at_display || entry.created_at || '';

            if (entry.is_legacy && !author && observationsLegacyAuthor) {
                author = observationsLegacyAuthor;
            }

            if (author && dateDisplay) {
                if (observationsMetaTemplate.indexOf('{{author}}') !== -1) {
                    var withAuthor = observationsMetaTemplate.replace(/\{\{\s*author\s*\}\}/g, author);
                    return withAuthor.replace(/\{\{\s*date\s*\}\}/g, dateDisplay);
                }

                return author + ' · ' + dateDisplay;
            }

            if (author) {
                if (observationsMetaNoDateTemplate.indexOf('{{author}}') !== -1) {
                    return observationsMetaNoDateTemplate.replace(/\{\{\s*author\s*\}\}/g, author);
                }

                return author;
            }

            return dateDisplay;
        }

        function renderObservacionesEmptyState() {
            if (!$observacionesList.length) {
                return;
            }

            var emptyMessage = escapeHtml(observationsEmptyText || '');

            $observacionesList.html(
                '<div class="list-group-item text-center text-muted py-3">' + emptyMessage + '</div>'
            );
        }

        function renderObservacionesList(recordId, observations) {
            if (!$observacionesList.length) {
                return;
            }

            if (!Array.isArray(observations) || observations.length === 0) {
                renderObservacionesEmptyState();
                return;
            }

            var normalizedRecordId = String(recordId || '');

            var itemsHtml = observations.map(function (entry) {
                var message = entry && entry.message ? entry.message : '';
                var meta = buildObservationMeta(entry);
                var isAuthor = Boolean(entry && entry.is_author);
                var observationId = entry && typeof entry.id !== 'undefined' && entry.id !== null
                    ? String(entry.id)
                    : '';
                var isEditing = currentObservationEditId !== null
                    && observationId
                    && String(currentObservationEditId) === observationId;
                var itemClasses = ['list-group-item', 'py-3'];

                if (isAuthor) {
                    itemClasses.push('bg-light');

                    if (isEditing) {
                        itemClasses.push('border-warning');
                        itemClasses.push('border-2');
                    } else {
                        itemClasses.push('border-primary');
                    }
                } else if (isEditing) {
                    itemClasses.push('border-warning');
                    itemClasses.push('border-2');
                }

                var messageHtml = formatObservationMessage(message);
                var metaHtml = escapeHtml(meta || '');
                var content = '<p class="mb-2">' + messageHtml + '</p>';

                if (metaHtml) {
                    content += '<p class="text-muted small mb-0">' + metaHtml + '</p>';
                }

                if (canAddObservaciones && isAuthor && observationId) {
                    var editLabel = observationEditLabel || 'Edit';

                    content += ''
                        + '<div class="d-flex justify-content-end mt-2">'
                        + '<button type="button" class="btn btn-sm btn-outline-primary"'
                        + ' data-action="edit-observacion" data-record-id="' + escapeHtml(normalizedRecordId) + '"'
                        + ' data-observation-id="' + escapeHtml(observationId) + '">'
                        + escapeHtml(editLabel)
                        + '</button>'
                        + '</div>';
                }

                var attributes = 'class="' + itemClasses.join(' ') + '"';

                if (observationId) {
                    attributes += ' data-observation-id="' + escapeHtml(observationId) + '"';
                }

                return '<div ' + attributes + '>' + content + '</div>';
            }).join('');

            $observacionesList.html(itemsHtml);
        }

        function setObservacionesLoading(isLoading) {
            if ($observacionesLoading.length) {
                if (isLoading) {
                    $observacionesLoading.removeClass('d-none');
                } else {
                    $observacionesLoading.addClass('d-none');
                }
            }

            if ($observacionesList.length) {
                $observacionesList.attr('aria-busy', isLoading ? 'true' : 'false');
            }
        }

        function updateObservationButton(recordId, count) {
            if (!recordId) {
                return;
            }

            var normalizedCount = normalizeObservationCount(count);
            var $button = $tableBody.find('[data-action="observaciones"][data-id="' + recordId + '"]');
            var state = getObservacionesState(recordId);
            var hasUnread = state ? Boolean(state.hasUnread) : false;

            if (!$button.length) {
                return;
            }

            var buttonLabel = observacionesActionLabel;

            if (normalizedCount > 0) {
                buttonLabel += ' (' + formatInteger(normalizedCount) + ')';
            }

            if (state) {
                state.count = normalizedCount;
            }

            if (hasUnread) {
                $button.removeClass('btn-outline-secondary btn-secondary').addClass('btn-warning');
            } else if (normalizedCount > 0) {
                $button.removeClass('btn-outline-secondary btn-warning').addClass('btn-secondary');
            } else {
                $button.removeClass('btn-secondary btn-warning').addClass('btn-outline-secondary');
            }

            $button.attr('data-observaciones-count', String(normalizedCount));
            $button.attr('data-observaciones-unread', hasUnread ? '1' : '0');

            if (state && state.lastCreatedAt) {
                $button.attr('data-observaciones-last-created-at', state.lastCreatedAt);
            } else {
                $button.removeAttr('data-observaciones-last-created-at');
            }

            if (hasUnread) {
                $button.attr('title', translate('reports.alert.observaciones_unread', 'You have unread observations.'));
            } else {
                $button.removeAttr('title');
            }

            $button.text(buttonLabel);
        }

        function renderEditClienteInfo(name, email) {
            if (!$editClienteInfo.length) {
                return;
            }

            var normalizedName = (name || '').trim();
            var normalizedEmail = (email || '').trim();
            var hasInfo = normalizedName !== '' || normalizedEmail !== '';

            if (!hasInfo) {
                $editClienteInfo.addClass('d-none').text('');
                return;
            }

            var template = clienteInfoTemplate;

            if (template) {
                var rendered = template.replace(/\{\{\s*name\s*\}\}/g, normalizedName);
                rendered = rendered.replace(/\{\{\s*email\s*\}\}/g, normalizedEmail);
                $editClienteInfo.text(rendered).removeClass('d-none');
                return;
            }

            var parts = [];
            if (normalizedName) {
                parts.push(normalizedName);
            }

            if (normalizedEmail) {
                parts.push(normalizedEmail);
            }

            $editClienteInfo.text(parts.join(' — ')).removeClass('d-none');
        }

        function handleEditClienteSelectionChange() {
            if (!$editClienteSelect.length) {
                return;
            }

            var $selected = $editClienteSelect.find('option:selected');
            var clientId = ($selected.val() || '').trim();
            var clientName = ($selected.attr('data-client-name') || '').trim();
            var clientEmail = ($selected.attr('data-client-email') || '').trim();

            if (clientId && $editClienteInput.length) {
                var displayValue = clientName || clientEmail;
                if (displayValue) {
                    $editClienteInput.val(displayValue);
                }
            }

            if (clientId) {
                renderEditClienteInfo(clientName, clientEmail);
            } else if (!$editClienteInput.val()) {
                $editClienteInfo.addClass('d-none').text('');
            }
        }

        function openEditModal(record) {
            if (!record || !editModal || !$editForm.length) {
                return;
            }

            if ($editForm[0]) {
                $editForm[0].reset();
            }

            clearEditFormErrors();
            clearEditFormAlert();

            var recordId = typeof record.id !== 'undefined' ? String(record.id) : '';
            currentEditRecordId = recordId;
            resetEditAttachmentsInput();
            clearEditAttachmentsFeedback();
            renderEditAttachmentsList(recordId);

            $editForm.find('[name="id"]').val(recordId);
            $editForm.find('[name="referencia"]').val(record.referencia || '');
            $editForm.find('[name="folio_aviso"]').val(record.folio_aviso || '');
            $editForm.find('[name="fecha_desembarque"]').val(record.fecha_desembarque || '');
            $editForm.find('[name="fecha_embarque"]').val(record.fecha_embarque || '');
            $editForm.find('[name="destino"]').val(record.destino || '');
            $editForm.find('[name="barco"]').val(record.barco || '');
            $editForm.find('[name="descripcion"]').val(record.descripcion || '');
            $editForm.find('[name="cliente"]').val(record.cliente || '');

            if ($editPedimentoInput.length) {
                $editPedimentoInput.val(record.pedimento || '');
            }

            if ($editCiplInput.length) {
                $editCiplInput.val(record.cipl || '');
            }

            if ($editManifiestoInput.length) {
                $editManifiestoInput.val(record.manifiesto || '');
            }

            if ($editClienteSelect.length) {
                var clientIdValue = record.client_id !== null && typeof record.client_id !== 'undefined'
                    ? String(record.client_id)
                    : '';

                $editClienteSelect.val(clientIdValue);

                if (clientIdValue) {
                    var $selectedOption = $editClienteSelect.find('option:selected');
                    var optionName = ($selectedOption.attr('data-client-name') || '').trim();
                    var optionEmail = ($selectedOption.attr('data-client-email') || '').trim();

                    if (optionName || optionEmail) {
                        renderEditClienteInfo(optionName, optionEmail);
                    } else if (record.client_name || record.client_email) {
                        renderEditClienteInfo(record.client_name || '', record.client_email || '');
                    } else {
                        $editClienteInfo.addClass('d-none').text('');
                    }
                } else if (record.client_name || record.client_email) {
                    renderEditClienteInfo(record.client_name || '', record.client_email || '');
                } else {
                    $editClienteInfo.addClass('d-none').text('');
                }
            }

            if ($editStatusSelect.length) {
                var statusIdValue = record.status_id !== null && typeof record.status_id !== 'undefined'
                    ? String(record.status_id)
                    : '';

                if (statusIdValue !== '') {
                    $editStatusSelect.val(statusIdValue);
                } else {
                    $editStatusSelect.prop('selectedIndex', 0);
                }
            }

            var pedimentosForForm = mergePrimaryReference(record.pedimento, record.pedimentos);
            var manifestsForForm = mergePrimaryReference(record.manifiesto, record.manifests);
            var ciplsForForm = mergePrimaryReference(record.cipl, record.cipls);
            var pedimentoPackages = normalizePedimentoPackagesForBridge(record.pedimento_headers);
            var packagesJson = '';

            if ($pedimentosHeaderInput.length) {
                $pedimentosHeaderInput.val('');
            }

            if ($pedimentosItemsInput.length) {
                $pedimentosItemsInput.val('');
            }

            if (pedimentoPackages.length > 0) {
                try {
                    packagesJson = JSON.stringify(pedimentoPackages);
                } catch (error) {
                    packagesJson = '';
                }
            }

            if ($pedimentosPackagesInput.length) {
                $pedimentosPackagesInput.val(packagesJson);
            }

            if ($pedimentosBridgeFeedback.length) {
                $pedimentosBridgeFeedback.removeClass('text-success text-danger text-info').addClass('text-muted').text('');
            }

            $(document).trigger('pedimentos:load-from-inputs', [{
                header: '',
                items: '',
                packages: packagesJson
            }]);

            var combinedPedimentos = normalizeReferenceList(pedimentosForForm.concat(collectPedimentosFromPackages(pedimentoPackages)));

            if (editReferenceGroups.pedimentos && typeof editReferenceGroups.pedimentos.setValues === 'function') {
                window.setTimeout(function () {
                    editReferenceGroups.pedimentos.setValues(combinedPedimentos);
                }, 0);
            }

            if (editReferenceGroups.manifests && typeof editReferenceGroups.manifests.setValues === 'function') {
                editReferenceGroups.manifests.setValues(manifestsForForm);
            }

            if (editReferenceGroups.cipls && typeof editReferenceGroups.cipls.setValues === 'function') {
                editReferenceGroups.cipls.setValues(ciplsForForm);
            }

            editModal.show();
        }

        function openObservacionesModal(record) {
            if (!record || !observacionesModalElement) {
                return;
            }

            if ($observacionesForm.length && $observacionesForm[0]) {
                $observacionesForm[0].reset();
            }

            clearObservacionesErrors();
            clearObservacionesAlert();
            clearObservationEditingState();

            var recordId = typeof record.id !== 'undefined' ? String(record.id) : '';

            currentObservacionesRecordId = recordId;

            if ($observacionesForm.length) {
                $observacionesForm.find('[name="id"]').val(recordId);
            }

            if ($observacionesTextarea.length) {
                $observacionesTextarea.val('');
            }

            if (Array.isArray(observacionesCache[recordId])) {
                renderObservacionesList(recordId, observacionesCache[recordId]);
            } else {
                renderObservacionesEmptyState();
            }

            fetchObservaciones(recordId);

            if (observacionesModal) {
                observacionesModal.show();
            }
        }

        if (editModalElement) {
            editModalElement.addEventListener('hidden.bs.modal', function () {
                if ($editForm.length && $editForm[0]) {
                    $editForm[0].reset();
                }

                clearEditFormErrors();
                clearEditFormAlert();

                currentEditRecordId = null;
                resetEditAttachmentsInput();
                clearEditAttachmentsFeedback();
                renderEditAttachmentsList(null);

                if ($editClienteInfo.length) {
                    $editClienteInfo.addClass('d-none').text('');
                }

                if ($editStatusSelect.length) {
                    $editStatusSelect.prop('selectedIndex', 0);
                }
            });
        }

        if (observacionesModalElement) {
            observacionesModalElement.addEventListener('hidden.bs.modal', function () {
                if ($observacionesForm.length && $observacionesForm[0]) {
                    $observacionesForm[0].reset();
                }

                clearObservacionesErrors();
                clearObservacionesAlert();

                currentObservacionesRecordId = null;
                clearObservationEditingState();
                setObservacionesLoading(false);
                renderObservacionesEmptyState();
            });
        }

        function isCancelledStatus(record) {
            if (!record) {
                return false;
            }

            var slug = String(record.status_slug || '').toLowerCase();

            if (slug === 'cancelled' || slug === 'canceled') {
                return true;
            }

            var label = String(record.status_label || '').toLowerCase();

            return label === 'cancelado'
                || label === 'cancelada'
                || label === 'cancelled'
                || label === 'canceled';
        }

        function openPedimentoDetails(record, type) {
            if (!record || !$pedimentoDetailsList.length || !$pedimentoDetailsEmpty.length) {
                return;
            }

            var mode = type === 'partidas' ? 'partidas' : 'pedimentos';
            var items = mode === 'partidas'
                ? normalizeReferenceList(record.pedimento_header_references)
                : normalizeReferenceList(record.pedimento_header_numbers);
            var titleText = pedimentoDetailsModalTitles[mode]
                || pedimentoDetailsModalTitles.pedimentos
                || pedimentoDetailsModalTitles.partidas;
            var emptyText = pedimentoDetailsModalEmptyTexts[mode]
                || pedimentoDetailsModalEmptyTexts.pedimentos
                || pedimentoDetailsModalEmptyTexts.partidas
                || notAvailableText;

            if ($pedimentoDetailsModalTitle.length) {
                $pedimentoDetailsModalTitle.text(titleText);
            }

            if (!items.length) {
                $pedimentoDetailsList.empty().addClass('d-none');
                $pedimentoDetailsEmpty.text(emptyText).removeClass('d-none');
            } else {
                var listItems = items.map(function (value) {
                    return '<li class="list-group-item">' + escapeHtml(value) + '</li>';
                });

                $pedimentoDetailsList.html(listItems.join('')).removeClass('d-none');
                $pedimentoDetailsEmpty.addClass('d-none');
            }

            if (pedimentoDetailsModal) {
                pedimentoDetailsModal.show();
            }
        }

        function renderRows(records) {
            currentRecords = Array.isArray(records) ? records.slice() : [];
            recordsById = {};

            if (!Array.isArray(records) || records.length === 0) {
                setTableMessage($tableBody, columnCount, tableEmptyText);
                setTableMessage($canceledTableBody, canceledColumnCount, canceledTableEmptyText);
                $canceledSection.addClass('d-none');

                return;
            }

            var activeRows = [];
            var canceledRows = [];

            records.forEach(function (record) {
                var clientName = String(record.client_name || record.cliente || '').trim();
                var clientEmail = String(record.client_email || '').trim();
                var clientDisplay = clientName !== '' ? clientName : clientEmail;
                if (!clientDisplay) {
                    clientDisplay = record.cliente || '';
                }
                var landingDate = record.fecha_desembarque_display || record.fecha_desembarque || '';
                var departureDate = record.fecha_embarque_display || record.fecha_embarque || '';
                var description = record.descripcion || '';
                var statusLabel = record.status_label || '';
                if (!statusLabel && record.status_slug) {
                    statusLabel = record.status_slug;
                }
                var recordId = typeof record.id !== 'undefined' ? String(record.id) : '';
                var attachments = normalizeAttachmentsList(record.attachments);
                record.attachments = attachments;

                var pedimentosList = normalizeReferenceList(record.pedimentos);
                var manifestsList = normalizeReferenceList(record.manifests);
                var ciplsList = normalizeReferenceList(record.cipls);
                var pedimentoHeaders = normalizePedimentoHeaders(record.pedimento_headers);
                var pedimentoHeaderNumbers;
                var pedimentoHeaderReferences;

                if (pedimentoHeaders.length) {
                    var headerNumbers = [];
                    var headerReferences = [];

                    pedimentoHeaders.forEach(function (header) {
                        if (header.num_pedimento) {
                            headerNumbers.push(header.num_pedimento);
                        }

                        if (Array.isArray(header.references) && header.references.length) {
                            header.references.forEach(function (reference) {
                                headerReferences.push(reference);
                            });
                        }
                    });

                    pedimentoHeaderNumbers = normalizeReferenceList(headerNumbers);
                    pedimentoHeaderReferences = normalizeReferenceList(headerReferences);
                } else {
                    pedimentoHeaderNumbers = normalizeReferenceList(record.pedimento_header_numbers);
                    pedimentoHeaderReferences = normalizeReferenceList(record.pedimento_header_references);
                }

                record.pedimentos = pedimentosList;
                record.manifests = manifestsList;
                record.cipls = ciplsList;
                record.pedimento_headers = pedimentoHeaders;
                record.pedimento_header_numbers = pedimentoHeaderNumbers;
                record.pedimento_header_references = pedimentoHeaderReferences;

                if (!record.pedimento || !String(record.pedimento).trim()) {
                    record.pedimento = pedimentosList.length ? pedimentosList[0] : '';
                }

                if (recordId) {
                    recordsById[recordId] = record;
                    syncObservacionesStateFromRecord(record);
                }

                if (!clientDisplay) {
                    clientDisplay = notAvailableText;
                }

                if (!description) {
                    description = notAvailableText;
                }

                if (!statusLabel) {
                    statusLabel = notAvailableText;
                }

                var pedimentoHeadersCell = '<td><span class="text-muted">' + escapeHtml(notAvailableText) + '</span></td>';
                var pedimentoPartidasCell = '<td><span class="text-muted">' + escapeHtml(notAvailableText) + '</span></td>';
                var actionCell = '';
                var observacionesCell = '';
                var observacionesCount = normalizeObservationCount(record.observaciones_count);
                var hasLegacyObservaciones = Boolean(record.has_legacy_observaciones);
                var hasUnreadObservaciones = Boolean(record.has_unread_observaciones);
                var lastCreatedAtValue = (record.observaciones_last_created_at || '').trim();
                var unreadTitle = hasUnreadObservaciones
                    ? translate('reports.alert.observaciones_unread', 'You have unread observations.')
                    : '';

                if (recordId && Object.prototype.hasOwnProperty.call(recordsById, recordId) && recordsById[recordId]) {
                    recordsById[recordId].observaciones_count = observacionesCount;
                    recordsById[recordId].has_legacy_observaciones = hasLegacyObservaciones;
                    recordsById[recordId].has_unread_observaciones = hasUnreadObservaciones;
                    recordsById[recordId].observaciones_last_created_at = record.observaciones_last_created_at || '';
                    recordsById[recordId].attachments = attachments.slice();
                    recordsById[recordId].pedimento_header_numbers = pedimentoHeaderNumbers.slice();
                    recordsById[recordId].pedimento_header_references = pedimentoHeaderReferences.slice();
                    recordsById[recordId].pedimento_headers = pedimentoHeaders.map(function (header) {
                        return {
                            num_pedimento: header.num_pedimento,
                            cve_pedimento: header.cve_pedimento || '',
                            razon_social: header.razon_social || '',
                            fecha_entrada: header.fecha_entrada || '',
                            fecha_pago: header.fecha_pago || '',
                            references: header.references.slice()
                        };
                    });
                }

                if (recordId && pedimentoHeaderNumbers.length) {
                    pedimentoHeadersCell = '<td>' +
                        '<button type="button" class="btn btn-link btn-sm p-0" data-action="show-pedimento-headers" data-record-id="' + escapeHtml(recordId) + '">' +
                            escapeHtml(pedimentoDetailsButtonLabel) + ' (' + pedimentoHeaderNumbers.length + ')' +
                        '</button>' +
                    '</td>';
                }

                if (recordId && pedimentoHeaderReferences.length) {
                    pedimentoPartidasCell = '<td>' +
                        '<button type="button" class="btn btn-link btn-sm p-0" data-action="show-pedimento-partidas" data-record-id="' + escapeHtml(recordId) + '">' +
                            escapeHtml(pedimentoDetailsButtonLabel) + ' (' + pedimentoHeaderReferences.length + ')' +
                        '</button>' +
                    '</td>';
                }

                if (canEditRecords) {
                    actionCell = '<td>' +
                        '<button type="button" class="btn btn-outline-primary btn-sm" data-action="edit" data-id="' + escapeHtml(recordId) + '">' +
                            escapeHtml(editActionLabel) +
                        '</button>' +
                    '</td>';
                }

                if (recordId) {
                    var hasObservaciones = observacionesCount > 0;
                    var observacionesButtonLabel = observacionesActionLabel;
                    var observacionesButtonClass;

                    if (hasUnreadObservaciones) {
                        observacionesButtonClass = 'btn btn-warning btn-sm';
                    } else if (hasObservaciones) {
                        observacionesButtonClass = 'btn btn-secondary btn-sm';
                    } else {
                        observacionesButtonClass = 'btn btn-outline-secondary btn-sm';
                    }

                    if (hasObservaciones) {
                        observacionesButtonLabel += ' (' + escapeHtml(formatInteger(observacionesCount)) + ')';
                    }

                    var unreadAttribute = hasUnreadObservaciones ? ' data-observaciones-unread="1"' : ' data-observaciones-unread="0"';
                    var lastCreatedAttribute = lastCreatedAtValue !== ''
                        ? ' data-observaciones-last-created-at="' + escapeHtml(lastCreatedAtValue) + '"'
                        : '';
                    var titleAttribute = unreadTitle !== ''
                        ? ' title="' + escapeHtml(unreadTitle) + '"'
                        : '';

                    observacionesCell = '<td>' +
                        '<button type="button" class="' + observacionesButtonClass + '" data-action="observaciones" data-id="' + escapeHtml(recordId) + '" data-observaciones-count="' + observacionesCount + '"' + unreadAttribute + lastCreatedAttribute + titleAttribute + '>' +
                            escapeHtml(observacionesButtonLabel) +
                        '</button>' +
                    '</td>';
                } else {
                    observacionesCell = '<td><span class="text-muted">' + escapeHtml(notAvailableText) + '</span></td>';
                }

                var attachmentsCell = buildAttachmentsCellHtml(recordId, attachments);
                var avisoCell;
                if (recordId) {
                    var avisoOrigin = String(record.aviso_origin || '').toLowerCase() === 'historical' ? 'historical' : 'system';
                    var avisoOriginLabel = avisoOrigin === 'historical'
                        ? (language === 'en' ? 'HISTORICAL' : 'HISTÓRICO')
                        : (language === 'en' ? 'SYSTEM' : 'SISTEMA');
                    var avisoOriginClass = avisoOrigin === 'historical' ? 'text-bg-dark' : 'text-bg-primary';
                    var avisoActions = '<div class="d-flex flex-column gap-1 align-items-stretch">' +
                        '<span class="badge ' + avisoOriginClass + ' align-self-center mb-1">' + escapeHtml(avisoOriginLabel) + '</span>' +
                        '<a class="btn btn-outline-primary btn-sm" href="aviso-expediente.php?id=' + encodeURIComponent(recordId) + '">' +
                            escapeHtml(expedienteActionLabel) +
                        '</a>';
                    if (canEditRecords) {
                        avisoActions += '<button type="button" class="btn btn-outline-secondary btn-sm" data-action="generate-aviso" data-id="' + escapeHtml(recordId) + '">' +
                            escapeHtml(avisoActionLabel) +
                        '</button>';
                    }
                    avisoActions += '</div>';
                    avisoCell = '<td>' + avisoActions + '</td>';
                } else {
                    avisoCell = '<td><span class="text-muted">' + escapeHtml(notAvailableText) + '</span></td>';
                }
                var rowHtml = '<tr>' +
                    '<td>' + escapeHtml(record.folio_aviso || '') + '</td>' +
                    '<td>' + escapeHtml(clientDisplay) + '</td>' +
                    '<td>' + escapeHtml(record.barco || '') + '</td>' +
                    '<td>' + escapeHtml(record.destino || '') + '</td>' +
                    '<td>' + escapeHtml(statusLabel) + '</td>' +
                    '<td>' + escapeHtml(description) + '</td>' +
                    pedimentoHeadersCell +
                    pedimentoPartidasCell +
                    observacionesCell +
                    attachmentsCell +
                    avisoCell +
                    '<td>' + escapeHtml(landingDate) + '</td>' +
                    '<td>' + escapeHtml(departureDate) + '</td>' +
                    '<td>' + escapeHtml(formatInteger(record.dias_transcurridos)) + '</td>' +
                    '<td>' + escapeHtml(formatInteger(record.dias_fuera)) + '</td>' +
                    actionCell +
                '</tr>';

                if (isCancelledStatus(record)) {
                    canceledRows.push(rowHtml);
                } else {
                    activeRows.push(rowHtml);
                }
            });

            if (activeRows.length === 0) {
                var emptyMessage = canceledRows.length > 0 ? activeTableEmptyText : tableEmptyText;
                setTableMessage($tableBody, columnCount, emptyMessage);
            } else {
                $tableBody.html(activeRows.join(''));
            }

            if (canceledRows.length === 0) {
                setTableMessage($canceledTableBody, canceledColumnCount, canceledTableEmptyText);
                $canceledSection.addClass('d-none');
            } else {
                $canceledTableBody.html(canceledRows.join(''));
                $canceledSection.removeClass('d-none');
            }
        }

        function syncRecordObservationCount(recordId, count, options) {
            if (!recordId) {
                return;
            }

            var normalizedCount = normalizeObservationCount(count);
            var state = getObservacionesState(recordId);

            if (Object.prototype.hasOwnProperty.call(recordsById, recordId) && recordsById[recordId]) {
                recordsById[recordId].observaciones_count = normalizedCount;
            }

            if (Array.isArray(currentRecords)) {
                currentRecords.forEach(function (record) {
                    if (!record) {
                        return;
                    }

                    var idValue = typeof record.id !== 'undefined' ? String(record.id) : '';

                    if (idValue === recordId) {
                        record.observaciones_count = normalizedCount;
                    }
                });
            }

            if (state) {
                state.count = normalizedCount;

                if (options && Object.prototype.hasOwnProperty.call(options, 'hasUnread')) {
                    state.hasUnread = Boolean(options.hasUnread);
                }

                if (options && Object.prototype.hasOwnProperty.call(options, 'lastCreatedAt')) {
                    state.lastCreatedAt = String(options.lastCreatedAt || '');
                }

                if (options && Object.prototype.hasOwnProperty.call(options, 'lastCreatedAtDisplay')) {
                    state.lastCreatedAtDisplay = String(options.lastCreatedAtDisplay || '');
                }

                if (options && Object.prototype.hasOwnProperty.call(options, 'referencia')) {
                    state.referencia = String(options.referencia || '');
                }
            }

            if (options && Object.prototype.hasOwnProperty.call(options, 'hasUnread')) {
                setRecordUnreadState(recordId, options.hasUnread);
            }

            updateObservationButton(recordId, normalizedCount);
        }

        function setObservacionesCache(recordId, observations, totalCount) {
            if (!recordId) {
                return;
            }

            if (Array.isArray(observations)) {
                observacionesCache[recordId] = observations.slice();
            } else {
                observacionesCache[recordId] = [];
            }

            var count = typeof totalCount === 'number'
                ? normalizeObservationCount(totalCount)
                : normalizeObservationCount(observacionesCache[recordId].length);

            syncRecordObservationCount(recordId, count);

            if (currentObservacionesRecordId === recordId) {
                renderObservacionesList(recordId, observacionesCache[recordId]);
            }
        }

        function appendObservation(recordId, observation, totalCount) {
            if (!recordId || !observation) {
                return;
            }

            if (!Array.isArray(observacionesCache[recordId])) {
                observacionesCache[recordId] = [];
            }

            observacionesCache[recordId].push(observation);

            var count = typeof totalCount === 'number'
                ? normalizeObservationCount(totalCount)
                : normalizeObservationCount(observacionesCache[recordId].length);

            syncRecordObservationCount(recordId, count);

            if (currentObservacionesRecordId === recordId) {
                renderObservacionesList(recordId, observacionesCache[recordId]);
            }

            var meta = {};

            if (observation && Object.prototype.hasOwnProperty.call(observation, 'created_at')) {
                meta.lastCreatedAt = observation.created_at;
            }

            if (observation && Object.prototype.hasOwnProperty.call(observation, 'created_at_display')) {
                meta.lastCreatedAtDisplay = observation.created_at_display;
            }

            markObservacionesAsRead(recordId, meta);
        }

        function replaceObservation(recordId, observation, totalCount) {
            if (!recordId || !observation) {
                return;
            }

            var normalizedRecordId = String(recordId);
            var observationId = typeof observation.id !== 'undefined' && observation.id !== null
                ? String(observation.id)
                : '';

            if (!observationId) {
                return;
            }

            if (!Array.isArray(observacionesCache[normalizedRecordId])) {
                observacionesCache[normalizedRecordId] = [];
            }

            var replaced = false;

            observacionesCache[normalizedRecordId] = observacionesCache[normalizedRecordId].map(function (entry) {
                if (!entry) {
                    return entry;
                }

                var entryId = typeof entry.id !== 'undefined' && entry.id !== null
                    ? String(entry.id)
                    : '';

                if (entryId && entryId === observationId) {
                    replaced = true;
                    return $.extend({}, entry, observation);
                }

                return entry;
            });

            if (!replaced) {
                observacionesCache[normalizedRecordId].push(observation);
            }

            var count = typeof totalCount === 'number'
                ? normalizeObservationCount(totalCount)
                : normalizeObservationCount(observacionesCache[normalizedRecordId].length);

            syncRecordObservationCount(normalizedRecordId, count);

            if (currentObservacionesRecordId === normalizedRecordId) {
                renderObservacionesList(normalizedRecordId, observacionesCache[normalizedRecordId]);
            }

            var meta = {};

            if (Object.prototype.hasOwnProperty.call(observation, 'created_at')) {
                meta.lastCreatedAt = observation.created_at;
            }

            if (Object.prototype.hasOwnProperty.call(observation, 'created_at_display')) {
                meta.lastCreatedAtDisplay = observation.created_at_display;
            }

            markObservacionesAsRead(normalizedRecordId, meta);
        }

        function fetchObservaciones(recordId) {
            if (!recordId) {
                return;
            }

            setObservacionesLoading(true);

            $.ajax({
                url: '../api/desembarques/observaciones.php',
                method: 'GET',
                data: { id: recordId },
                dataType: 'json'
            })
                .done(function (response) {
                    if (currentObservacionesRecordId !== recordId) {
                        return;
                    }

                    if (response && response.success) {
                        var observations = Array.isArray(response.data) ? response.data : [];
                        var totalCount = typeof response.count !== 'undefined'
                            ? response.count
                            : observations.length;

                        setObservacionesCache(recordId, observations, totalCount);

                        var lastCreatedAt = typeof response.last_created_at === 'string'
                            ? response.last_created_at
                            : '';
                        var lastCreatedAtDisplay = typeof response.last_created_at_display === 'string'
                            ? response.last_created_at_display
                            : '';

                        markObservacionesAsRead(recordId, {
                            lastCreatedAt: lastCreatedAt,
                            lastCreatedAtDisplay: lastCreatedAtDisplay
                        });
                    } else {
                        renderObservacionesList(recordId, observacionesCache[recordId] || []);

                        var warningMessage = response && response.message
                            ? response.message
                            : observationsLoadErrorText;

                        showObservacionesAlert('warning', warningMessage);
                    }
                })
                .fail(function (jqXHR) {
                    if (currentObservacionesRecordId !== recordId) {
                        return;
                    }

                    var status = jqXHR ? jqXHR.status : 0;
                    var message = observationsLoadErrorText;

                    if (jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.message) {
                        message = jqXHR.responseJSON.message;
                    }

                    if (status === 401) {
                        message = translate('common.session_expired', 'Your session has expired. Please sign in again.');
                    } else if (status === 419) {
                        message = translate('common.csrf_token_invalid', 'The security token has expired. Please refresh the page and try again.');
                    }

                    var alertType = status === 401 || status === 419 ? 'warning' : 'danger';
                    showObservacionesAlert(alertType, message);

                    if (status === 401) {
                        window.setTimeout(function () {
                            window.location.href = 'login.php?status=expired&lang=' + encodeURIComponent(language);
                        }, 1500);
                    }
                })
                .always(function () {
                    if (currentObservacionesRecordId === recordId) {
                        setObservacionesLoading(false);
                    }
                });
        }

        function sanitizeFileName(value) {
            return String(value || '')
                .replace(/[^A-Za-z0-9_\-]+/g, '_')
                .replace(/_+/g, '_')
                .replace(/^_+|_+$/g, '') || 'report';
        }

        function buildExportTimestamp() {
            var now = new Date();
            var year = String(now.getFullYear());
            var month = String(now.getMonth() + 1);
            var day = String(now.getDate());
            var hours = String(now.getHours());
            var minutes = String(now.getMinutes());

            if (month.length < 2) {
                month = '0' + month;
            }

            if (day.length < 2) {
                day = '0' + day;
            }

            if (hours.length < 2) {
                hours = '0' + hours;
            }

            if (minutes.length < 2) {
                minutes = '0' + minutes;
            }

            return year + month + day + '_' + hours + minutes;
        }

        function getExportFileName(extension) {
            var baseName = translate('reports.export.file_name', 'unloading-reports');
            var safeBaseName = sanitizeFileName(baseName);
            var timestamp = buildExportTimestamp();

            return safeBaseName + '_' + timestamp + '.' + extension;
        }

        function toggleChartEmptyMessage($element, shouldShow) {
            if (!$element || !$element.length) {
                return;
            }

            if (shouldShow) {
                $element.removeClass('d-none');
            } else {
                $element.addClass('d-none');
            }
        }

        function updateRecordsByClientChart(records) {
            if (!chartsEnabled || !chartRecordsByClientElement) {
                return;
            }

            if (!chartLibraryLoaded) {
                toggleChartEmptyMessage($chartEmptyRecordsByClient, true);
                return;
            }

            var countsByClient = {};

            if (Array.isArray(records) && records.length) {
                records.forEach(function (record) {
                    var primary = record.client_name || record.cliente || '';
                    var secondary = record.client_email || '';
                    var label = buildDisplayValue(primary, secondary);

                    if (!label) {
                        label = record.cliente || '';
                    }

                    if (!label) {
                        label = translate('reports.charts.unknown_client', 'Unassigned client');
                    }

                    if (!Object.prototype.hasOwnProperty.call(countsByClient, label)) {
                        countsByClient[label] = 0;
                    }

                    countsByClient[label] += 1;
                });
            }

            var labels = Object.keys(countsByClient).sort(function (a, b) {
                return countsByClient[b] - countsByClient[a];
            });

            var hasData = labels.length > 0;
            toggleChartEmptyMessage($chartEmptyRecordsByClient, !hasData);

            if (!hasData) {
                if (recordsByClientChart) {
                    recordsByClientChart.destroy();
                    recordsByClientChart = null;
                }

                return;
            }

            var values = labels.map(function (label) {
                return countsByClient[label];
            });

            var datasetLabel = translate('reports.summary.records', 'Records');
            var datasetColors = [];
            var indexesNeedingGeneratedColor = [];

            labels.forEach(function (label, index) {
                var overrideColor = resolveClientColorOverride(label);

                if (overrideColor) {
                    datasetColors[index] = overrideColor;
                } else {
                    indexesNeedingGeneratedColor.push(index);
                }
            });

            var generatedColors = generateChartColors(indexesNeedingGeneratedColor.length);

            indexesNeedingGeneratedColor.forEach(function (labelIndex, offset) {
                datasetColors[labelIndex] = generatedColors[offset];
            });

            if (!recordsByClientChart) {
                recordsByClientChart = new window.Chart(chartRecordsByClientElement, {
                    type: 'bar',
                    data: {
                        labels: [],
                        datasets: []
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            },
                            title: {
                                display: true,
                                text: translate('reports.charts.records_by_client', 'Records by client')
                            }
                        },
                        scales: {
                            x: {
                                ticks: {
                                    maxRotation: 45,
                                    minRotation: 0,
                                    autoSkip: false
                                }
                            },
                            y: {
                                beginAtZero: true,
                                precision: 0
                            }
                        }
                    }
                });
            }

            recordsByClientChart.data.labels = labels;

            if (recordsByClientChart.data.datasets.length === 0) {
                recordsByClientChart.data.datasets.push({
                    label: datasetLabel,
                    data: values,
                    backgroundColor: datasetColors,
                    borderColor: datasetColors,
                    borderWidth: 1
                });
            } else {
                recordsByClientChart.data.datasets[0].label = datasetLabel;
                recordsByClientChart.data.datasets[0].data = values;
                recordsByClientChart.data.datasets[0].backgroundColor = datasetColors;
                recordsByClientChart.data.datasets[0].borderColor = datasetColors;
                recordsByClientChart.data.datasets[0].borderWidth = 1;
            }

            recordsByClientChart.update();
        }

        function updateRecordsByStatusChart(records) {
            if (!chartsEnabled || !chartRecordsByStatusElement) {
                return;
            }

            var countsByStatus = {};

            if (Array.isArray(records) && records.length) {
                records.forEach(function (record) {
                    if (!record) {
                        return;
                    }

                    var rawLabel = record.status_label || record.status || '';
                    var label = rawLabel ? String(rawLabel) : translate('reports.table.not_available', 'Not available');

                    if (!Object.prototype.hasOwnProperty.call(countsByStatus, label)) {
                        countsByStatus[label] = 0;
                    }

                    countsByStatus[label] += 1;
                });
            }

            var entries = Object.keys(countsByStatus).map(function (label) {
                return {
                    label: label,
                    count: countsByStatus[label]
                };
            });

            entries.sort(function (a, b) {
                if (b.count === a.count) {
                    return a.label.localeCompare(b.label);
                }

                return b.count - a.count;
            });

            analyticsSnapshot.statusTotals = entries.map(function (entry) {
                return {
                    label: entry.label,
                    value: entry.count
                };
            });

            var hasData = entries.length > 0;
            toggleChartEmptyMessage($chartEmptyRecordsByStatus, !hasData);

            if (!hasData) {
                if (recordsByStatusChart) {
                    recordsByStatusChart.destroy();
                    recordsByStatusChart = null;
                }

                return;
            }

            if (!chartLibraryLoaded) {
                return;
            }

            var labels = entries.map(function (entry) {
                return entry.label;
            });
            var values = entries.map(function (entry) {
                return entry.count;
            });
            var datasetLabel = translate('reports.summary.records', 'Records');
            var datasetColors = generateChartColors(labels.length);

            if (!recordsByStatusChart) {
                recordsByStatusChart = new window.Chart(chartRecordsByStatusElement, {
                    type: 'doughnut',
                    data: {
                        labels: [],
                        datasets: []
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom'
                            }
                        }
                    }
                });
            }

            recordsByStatusChart.data.labels = labels;

            if (recordsByStatusChart.data.datasets.length === 0) {
                recordsByStatusChart.data.datasets.push({
                    label: datasetLabel,
                    data: values,
                    backgroundColor: datasetColors,
                    borderColor: datasetColors,
                    borderWidth: 1
                });
            } else {
                recordsByStatusChart.data.datasets[0].label = datasetLabel;
                recordsByStatusChart.data.datasets[0].data = values;
                recordsByStatusChart.data.datasets[0].backgroundColor = datasetColors;
                recordsByStatusChart.data.datasets[0].borderColor = datasetColors;
                recordsByStatusChart.data.datasets[0].borderWidth = 1;
            }

            recordsByStatusChart.update();
        }

        function updateRecordsByVesselChart(records) {
            if (!chartsEnabled || !chartRecordsByVesselElement) {
                return;
            }

            var countsByVessel = {};
            var unknownVesselLabel = translate('reports.charts.unknown_vessel', 'Unassigned vessel');

            if (Array.isArray(records) && records.length) {
                records.forEach(function (record) {
                    if (!record) {
                        return;
                    }

                    var label = record.barco || '';

                    if (!label) {
                        label = unknownVesselLabel;
                    }

                    if (!Object.prototype.hasOwnProperty.call(countsByVessel, label)) {
                        countsByVessel[label] = 0;
                    }

                    countsByVessel[label] += 1;
                });
            }

            var entries = Object.keys(countsByVessel).map(function (label) {
                return {
                    label: label,
                    count: countsByVessel[label]
                };
            });

            entries.sort(function (a, b) {
                if (b.count === a.count) {
                    return a.label.localeCompare(b.label);
                }

                return b.count - a.count;
            });

            analyticsSnapshot.vesselTotals = entries.map(function (entry) {
                return {
                    label: entry.label,
                    value: entry.count
                };
            });

            var hasData = entries.length > 0;
            toggleChartEmptyMessage($chartEmptyRecordsByVessel, !hasData);

            if (!hasData) {
                if (recordsByVesselChart) {
                    recordsByVesselChart.destroy();
                    recordsByVesselChart = null;
                }

                return;
            }

            var maxEntries = 8;
            var displayEntries = entries.slice(0, maxEntries);

            if (entries.length > maxEntries) {
                var otherCount = entries.slice(maxEntries).reduce(function (sum, entry) {
                    return sum + Number(entry.count || 0);
                }, 0);

                if (otherCount > 0) {
                    displayEntries.push({
                        label: translate('reports.charts.other', 'Other'),
                        count: otherCount
                    });
                }
            }

            var labels = displayEntries.map(function (entry) {
                return entry.label;
            });
            var values = displayEntries.map(function (entry) {
                return entry.count;
            });
            var datasetLabel = translate('reports.summary.records', 'Records');
            var datasetColors = generateChartColors(labels.length);

            if (!chartLibraryLoaded) {
                return;
            }

            if (!recordsByVesselChart) {
                recordsByVesselChart = new window.Chart(chartRecordsByVesselElement, {
                    type: 'bar',
                    data: {
                        labels: [],
                        datasets: []
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        indexAxis: 'y',
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            x: {
                                beginAtZero: true,
                                ticks: {
                                    precision: 0
                                }
                            }
                        }
                    }
                });
            }

            recordsByVesselChart.data.labels = labels;

            if (recordsByVesselChart.data.datasets.length === 0) {
                recordsByVesselChart.data.datasets.push({
                    label: datasetLabel,
                    data: values,
                    backgroundColor: datasetColors,
                    borderColor: datasetColors,
                    borderWidth: 1
                });
            } else {
                recordsByVesselChart.data.datasets[0].label = datasetLabel;
                recordsByVesselChart.data.datasets[0].data = values;
                recordsByVesselChart.data.datasets[0].backgroundColor = datasetColors;
                recordsByVesselChart.data.datasets[0].borderColor = datasetColors;
                recordsByVesselChart.data.datasets[0].borderWidth = 1;
            }

            recordsByVesselChart.update();
        }

        function updateDaysDistributionChart(summary) {
            if (!chartsEnabled || !chartDaysDistributionElement) {
                if (!chartsEnabled) {
                    resetAnalyticsSnapshot();
                }
                return;
            }

            if (!chartLibraryLoaded) {
                toggleChartEmptyMessage($chartEmptyDaysDistribution, true);
                return;
            }

            var safeSummary = summary || {};
            var totalDiasTranscurridos = Number(safeSummary.total_dias_transcurridos || 0);
            var totalDiasFuera = Number(safeSummary.total_dias_fuera || 0);

            if (!Number.isFinite(totalDiasTranscurridos)) {
                totalDiasTranscurridos = 0;
            }

            if (!Number.isFinite(totalDiasFuera)) {
                totalDiasFuera = 0;
            }

            var dataset = [totalDiasTranscurridos, totalDiasFuera];
            var hasData = dataset.some(function (value) {
                return Number(value) > 0;
            });

            toggleChartEmptyMessage($chartEmptyDaysDistribution, !hasData);

            if (!hasData) {
                if (daysDistributionChart) {
                    daysDistributionChart.destroy();
                    daysDistributionChart = null;
                }

                return;
            }

            var labels = [
                translate('reports.summary.days_elapsed', 'Days elapsed'),
                translate('reports.summary.days_out', 'Dwell time (days)')
            ];

            if (!daysDistributionChart) {
                daysDistributionChart = new window.Chart(chartDaysDistributionElement, {
                    type: 'doughnut',
                    data: {
                        labels: labels,
                        datasets: [
                            {
                                data: dataset,
                                backgroundColor: ['#0d6efd', '#198754']
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            title: {
                                display: true,
                                text: translate('reports.charts.days_distribution', 'Days distribution')
                            }
                        }
                    }
                });

                return;
            }

            daysDistributionChart.data.labels = labels;

            if (daysDistributionChart.data.datasets.length === 0) {
                daysDistributionChart.data.datasets.push({
                    data: dataset,
                    backgroundColor: ['#0d6efd', '#198754']
                });
            } else {
                daysDistributionChart.data.datasets[0].data = dataset;
                daysDistributionChart.data.datasets[0].backgroundColor = ['#0d6efd', '#198754'];
            }

            daysDistributionChart.update();
        }

        function updateRecordsTrendChart(records) {
            if (!chartsEnabled || !chartRecordsTrendElement) {
                resetAnalyticsSnapshot();
                return;
            }

            var countsByPeriod = {};

            if (Array.isArray(records) && records.length) {
                records.forEach(function (record) {
                    var recordDate = getRecordPrimaryDate(record);
                    var periodKey = buildMonthKey(recordDate);

                    if (!periodKey) {
                        return;
                    }

                    if (!Object.prototype.hasOwnProperty.call(countsByPeriod, periodKey)) {
                        countsByPeriod[periodKey] = 0;
                    }

                    countsByPeriod[periodKey] += 1;
                });
            }

            var periodKeys = Object.keys(countsByPeriod).sort();
            var hasData = periodKeys.length > 0;

            toggleChartEmptyMessage($chartEmptyRecordsTrend, !hasData);

            if (!hasData) {
                if (recordsTrendChart) {
                    recordsTrendChart.destroy();
                    recordsTrendChart = null;
                }

                analyticsSnapshot.trend = [];

                return;
            }

            var labels = [];
            var values = [];
            var points = [];

            periodKeys.forEach(function (periodKey) {
                var label = formatMonthLabel(periodKey);
                var count = countsByPeriod[periodKey];

                labels.push(label);
                values.push(count);
                points.push({
                    period: periodKey,
                    label: label,
                    count: count
                });
            });

            analyticsSnapshot.trend = points;

            if (!chartLibraryLoaded) {
                return;
            }

            var datasetLabel = translate('reports.summary.records', 'Records');

            if (!recordsTrendChart) {
                recordsTrendChart = new window.Chart(chartRecordsTrendElement, {
                    type: 'line',
                    data: {
                        labels: [],
                        datasets: []
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: {
                            mode: 'index',
                            intersect: false
                        },
                        plugins: {
                            legend: {
                                display: false
                            },
                            title: {
                                display: true,
                                text: translate('reports.charts.records_trend', 'Monthly trend')
                            }
                        },
                        scales: {
                            x: {
                                ticks: {
                                    maxRotation: 0,
                                    autoSkip: true
                                }
                            },
                            y: {
                                beginAtZero: true,
                                precision: 0
                            }
                        }
                    }
                });
            }

            recordsTrendChart.data.labels = labels;

            if (recordsTrendChart.data.datasets.length === 0) {
                recordsTrendChart.data.datasets.push({
                    label: datasetLabel,
                    data: values,
                    borderColor: '#0d6efd',
                    backgroundColor: 'rgba(13, 110, 253, 0.15)',
                    fill: true,
                    tension: 0.3
                });
            } else {
                recordsTrendChart.data.datasets[0].label = datasetLabel;
                recordsTrendChart.data.datasets[0].data = values;
                recordsTrendChart.data.datasets[0].borderColor = '#0d6efd';
                recordsTrendChart.data.datasets[0].backgroundColor = 'rgba(13, 110, 253, 0.15)';
                recordsTrendChart.data.datasets[0].fill = true;
                recordsTrendChart.data.datasets[0].tension = 0.3;
            }

            recordsTrendChart.update();
        }

        function updateAverageByStatusChart(records) {
            if (!chartsEnabled || !chartAverageByStatusElement) {
                analyticsSnapshot.statusAverages = [];
                return;
            }

            var totalsByStatus = {};
            var countsByStatus = {};

            if (Array.isArray(records) && records.length) {
                records.forEach(function (record) {
                    if (!record) {
                        return;
                    }

                    var label = record.status_label || record.status || '';

                    if (!label) {
                        label = translate('reports.table.not_available', 'Not available');
                    }

                    if (!Object.prototype.hasOwnProperty.call(totalsByStatus, label)) {
                        totalsByStatus[label] = 0;
                        countsByStatus[label] = 0;
                    }

                    var days = Number(record.dias_transcurridos || 0);

                    if (!Number.isFinite(days)) {
                        days = 0;
                    }

                    totalsByStatus[label] += days;
                    countsByStatus[label] += 1;
                });
            }

            var labels = Object.keys(totalsByStatus);

            var hasData = labels.length > 0;
            toggleChartEmptyMessage($chartEmptyAverageByStatus, !hasData);

            if (!hasData) {
                if (averageByStatusChart) {
                    averageByStatusChart.destroy();
                    averageByStatusChart = null;
                }

                analyticsSnapshot.statusAverages = [];

                return;
            }

            labels.sort(function (a, b) {
                var averageA = totalsByStatus[a] / countsByStatus[a];
                var averageB = totalsByStatus[b] / countsByStatus[b];

                if (!Number.isFinite(averageA)) {
                    averageA = 0;
                }

                if (!Number.isFinite(averageB)) {
                    averageB = 0;
                }

                return averageB - averageA;
            });

            var values = labels.map(function (label) {
                var total = totalsByStatus[label];
                var count = countsByStatus[label];
                var average = count > 0 ? total / count : 0;

                if (!Number.isFinite(average)) {
                    average = 0;
                }

                return Number(average.toFixed(2));
            });

            analyticsSnapshot.statusAverages = labels.map(function (label, index) {
                return {
                    label: label,
                    average: values[index],
                    count: countsByStatus[label]
                };
            });

            if (!chartLibraryLoaded) {
                return;
            }

            var datasetLabel = translate('reports.charts.average_days', 'Average days');
            var datasetColors = generateChartColors(labels.length);

            if (!averageByStatusChart) {
                averageByStatusChart = new window.Chart(chartAverageByStatusElement, {
                    type: 'bar',
                    data: {
                        labels: [],
                        datasets: []
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        indexAxis: 'y',
                        plugins: {
                            legend: {
                                display: false
                            },
                            title: {
                                display: true,
                                text: translate('reports.charts.average_by_status', 'Average by status')
                            }
                        },
                        scales: {
                            x: {
                                beginAtZero: true
                            }
                        }
                    }
                });
            }

            averageByStatusChart.data.labels = labels;

            if (averageByStatusChart.data.datasets.length === 0) {
                averageByStatusChart.data.datasets.push({
                    label: datasetLabel,
                    data: values,
                    backgroundColor: datasetColors,
                    borderColor: datasetColors,
                    borderWidth: 1
                });
            } else {
                averageByStatusChart.data.datasets[0].label = datasetLabel;
                averageByStatusChart.data.datasets[0].data = values;
                averageByStatusChart.data.datasets[0].backgroundColor = datasetColors;
                averageByStatusChart.data.datasets[0].borderColor = datasetColors;
                averageByStatusChart.data.datasets[0].borderWidth = 1;
            }

            averageByStatusChart.update();
        }

        function updateClientPerformanceChart(records) {
            if (!chartsEnabled || !chartClientPerformanceElement) {
                analyticsSnapshot.clientPerformance = [];
                return;
            }

            var totalsByClient = {};
            var countsByClient = {};

            if (Array.isArray(records) && records.length) {
                records.forEach(function (record) {
                    if (!record) {
                        return;
                    }

                    var primary = record.client_name || record.cliente || '';
                    var secondary = record.client_email || '';
                    var label = buildDisplayValue(primary, secondary);

                    if (!label) {
                        label = record.cliente || '';
                    }

                    if (!label) {
                        label = translate('reports.charts.unknown_client', 'Unassigned client');
                    }

                    if (!Object.prototype.hasOwnProperty.call(totalsByClient, label)) {
                        totalsByClient[label] = 0;
                        countsByClient[label] = 0;
                    }

                    var daysOut = Number(record.dias_fuera || 0);

                    if (!Number.isFinite(daysOut)) {
                        daysOut = 0;
                    }

                    totalsByClient[label] += daysOut;
                    countsByClient[label] += 1;
                });
            }

            var labels = Object.keys(totalsByClient);
            var hasData = labels.length > 0;

            toggleChartEmptyMessage($chartEmptyClientPerformance, !hasData);

            if (!hasData) {
                if (clientPerformanceChart) {
                    clientPerformanceChart.destroy();
                    clientPerformanceChart = null;
                }

                analyticsSnapshot.clientPerformance = [];

                return;
            }

            labels.sort(function (a, b) {
                var averageA = totalsByClient[a] / countsByClient[a];
                var averageB = totalsByClient[b] / countsByClient[b];

                if (!Number.isFinite(averageA)) {
                    averageA = 0;
                }

                if (!Number.isFinite(averageB)) {
                    averageB = 0;
                }

                return averageB - averageA;
            });

            var values = labels.map(function (label) {
                var total = totalsByClient[label];
                var count = countsByClient[label];
                var average = count > 0 ? total / count : 0;

                if (!Number.isFinite(average)) {
                    average = 0;
                }

                return Number(average.toFixed(2));
            });

            analyticsSnapshot.clientPerformance = labels.map(function (label, index) {
                return {
                    label: label,
                    average: values[index],
                    count: countsByClient[label]
                };
            });

            if (!chartLibraryLoaded) {
                return;
            }

            var datasetLabel = translate('reports.charts.average_days_out', 'Average dwell time');
            var datasetColors = generateChartColors(labels.length);

            if (!clientPerformanceChart) {
                clientPerformanceChart = new window.Chart(chartClientPerformanceElement, {
                    type: 'bar',
                    data: {
                        labels: [],
                        datasets: []
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            },
                            title: {
                                display: true,
                                text: translate('reports.charts.client_performance', 'Client performance')
                            }
                        },
                        scales: {
                            x: {
                                ticks: {
                                    maxRotation: 45,
                                    minRotation: 0,
                                    autoSkip: false
                                }
                            },
                            y: {
                                beginAtZero: true
                            }
                        }
                    }
                });
            }

            clientPerformanceChart.data.labels = labels;

            if (clientPerformanceChart.data.datasets.length === 0) {
                clientPerformanceChart.data.datasets.push({
                    label: datasetLabel,
                    data: values,
                    backgroundColor: datasetColors,
                    borderColor: datasetColors,
                    borderWidth: 1
                });
            } else {
                clientPerformanceChart.data.datasets[0].label = datasetLabel;
                clientPerformanceChart.data.datasets[0].data = values;
                clientPerformanceChart.data.datasets[0].backgroundColor = datasetColors;
                clientPerformanceChart.data.datasets[0].borderColor = datasetColors;
                clientPerformanceChart.data.datasets[0].borderWidth = 1;
            }

            clientPerformanceChart.update();
        }

        function updateCharts(records, summary) {
            if (!chartsEnabled) {
                resetAnalyticsSnapshot();
                return;
            }

            var safeRecords = Array.isArray(records) ? records : [];
            updateRecordsByClientChart(safeRecords);
            updateRecordsByStatusChart(safeRecords);
            updateRecordsByVesselChart(safeRecords);
            updateDaysDistributionChart(summary || {});
            updateRecordsTrendChart(safeRecords);
            updateAverageByStatusChart(safeRecords);
            updateClientPerformanceChart(safeRecords);
        }

        function exportToPdf() {
            if (!currentRecords.length) {
                showAlert('warning', translate('reports.export.error_no_data', 'There is no data to export.'));
                return;
            }

            var jsPdfNamespace = window.jspdf || {};

            if (typeof window.jsPDF === 'function' && typeof jsPdfNamespace.jsPDF !== 'function') {
                jsPdfNamespace.jsPDF = window.jsPDF;
            }

            if (typeof jsPdfNamespace.jsPDF !== 'function') {
                showAlert('warning', translate('reports.export.error_library', 'Export is not available.'));
                return;
            }

            var doc = new jsPdfNamespace.jsPDF({
                orientation: 'landscape',
                unit: 'pt',
                format: 'a4'
            });

            if (typeof doc.autoTable !== 'function') {
                showAlert('warning', translate('reports.export.error_library', 'Export is not available.'));
                return;
            }

            var headers = exportColumns.map(function (column) {
                return column.label;
            });

            var rows = currentRecords.map(function (record) {
                return exportColumns.map(function (column) {
                    var value = column.getValue(record);

                    if (column.isNumeric) {
                        return formatInteger(value);
                    }

                    if (value === null || value === undefined) {
                        return '';
                    }

                    return String(value);
                });
            });

            var title = translate('reports.header', document.title || 'Reports');

            doc.setFontSize(14);
            doc.text(String(title), 40, 40);
            doc.autoTable({
                head: [headers],
                body: rows,
                startY: 60,
                styles: {
                    fontSize: 10,
                    cellPadding: 6
                },
                headStyles: {
                    fillColor: [13, 110, 253],
                    textColor: [255, 255, 255]
                }
            });

            doc.save(getExportFileName('pdf'));
        }

        function normalizeSheetName(name) {
            var value = String(name || 'Reports')
                .replace(/[\\/?*\[\]:]/g, ' ')
                .trim();

            if (value === '') {
                value = 'Reports';
            }

            if (value.length > 31) {
                value = value.slice(0, 31);
            }

            return value;
        }

        function exportToExcel() {
            if (!currentRecords.length) {
                showAlert('warning', translate('reports.export.error_no_data', 'There is no data to export.'));
                return;
            }

            if (!window.XLSX || !window.XLSX.utils || typeof window.XLSX.utils.json_to_sheet !== 'function') {
                showAlert('warning', translate('reports.export.error_library', 'Export is not available.'));
                return;
            }

            var exportData = currentRecords.map(function (record) {
                var row = {};

                exportColumns.forEach(function (column) {
                    var value = column.getValue(record);

                    if (column.isNumeric) {
                        var numericValue = Number(value);
                        row[column.label] = Number.isFinite(numericValue) ? numericValue : 0;
                    } else if (value === null || value === undefined) {
                        row[column.label] = '';
                    } else {
                        row[column.label] = value;
                    }
                });

                return row;
            });

            var worksheet = window.XLSX.utils.json_to_sheet(exportData, {
                header: exportColumns.map(function (column) {
                    return column.label;
                })
            });

            var workbook = window.XLSX.utils.book_new();
            var sheetName = normalizeSheetName(translate('reports.header', 'Reports'));

            window.XLSX.utils.book_append_sheet(workbook, worksheet, sheetName);
            window.XLSX.writeFile(workbook, getExportFileName('xlsx'));
        }

        function exportAnalyticsSummary() {
            if (!chartsEnabled) {
                showAlert('info', analyticsExportEmptyText);
                return;
            }

            var hasTrend = Array.isArray(analyticsSnapshot.trend) && analyticsSnapshot.trend.length > 0;
            var hasStatus = Array.isArray(analyticsSnapshot.statusAverages) && analyticsSnapshot.statusAverages.length > 0;
            var hasClients = Array.isArray(analyticsSnapshot.clientPerformance) && analyticsSnapshot.clientPerformance.length > 0;
            var hasStatusTotals = Array.isArray(analyticsSnapshot.statusTotals) && analyticsSnapshot.statusTotals.length > 0;
            var hasVessels = Array.isArray(analyticsSnapshot.vesselTotals) && analyticsSnapshot.vesselTotals.length > 0;

            if (!hasTrend && !hasStatus && !hasClients && !hasStatusTotals && !hasVessels) {
                showAlert('info', analyticsExportEmptyText);
                return;
            }

            var lines = [];
            var sections = [];

            if (hasStatusTotals) {
                sections.push(function (output) {
                    output.push(translate('reports.export.analytics_status_totals', 'Records by status'));
                    output.push([
                        escapeCsvValue(translate('reports.export.analytics_status_label', 'Status')),
                        escapeCsvValue(translate('reports.export.analytics_status_records', 'Records'))
                    ].join(','));

                    analyticsSnapshot.statusTotals.forEach(function (entry) {
                        output.push([
                            escapeCsvValue(entry.label),
                            escapeCsvValue(formatInteger(entry.value))
                        ].join(','));
                    });
                });
            }

            if (hasVessels) {
                sections.push(function (output) {
                    output.push(translate('reports.export.analytics_vessels', 'Records by vessel'));
                    output.push([
                        escapeCsvValue(translate('reports.export.analytics_vessel_label', 'Vessel')),
                        escapeCsvValue(translate('reports.export.analytics_vessel_records', 'Records'))
                    ].join(','));

                    analyticsSnapshot.vesselTotals.forEach(function (entry) {
                        output.push([
                            escapeCsvValue(entry.label),
                            escapeCsvValue(formatInteger(entry.value))
                        ].join(','));
                    });
                });
            }

            if (hasTrend) {
                sections.push(function (output) {
                    output.push(translate('reports.charts.records_trend', 'Monthly trend'));
                    output.push([
                        escapeCsvValue(translate('reports.export.analytics_trend_period', 'Period')),
                        escapeCsvValue(translate('reports.export.analytics_trend_records', 'Records'))
                    ].join(','));

                    analyticsSnapshot.trend.forEach(function (point) {
                        output.push([
                            escapeCsvValue(point.label),
                            escapeCsvValue(formatInteger(point.count))
                        ].join(','));
                    });
                });
            }

            if (hasStatus) {
                sections.push(function (output) {
                    output.push(translate('reports.export.analytics_status', 'Average by status'));
                    output.push([
                        escapeCsvValue(translate('reports.export.analytics_status_label', 'Status')),
                        escapeCsvValue(translate('reports.export.analytics_status_average', 'Average days')),
                        escapeCsvValue(translate('reports.summary.records', 'Records'))
                    ].join(','));

                    analyticsSnapshot.statusAverages.forEach(function (entry) {
                        output.push([
                            escapeCsvValue(entry.label),
                            escapeCsvValue(formatDecimal(entry.average)),
                            escapeCsvValue(formatInteger(entry.count))
                        ].join(','));
                    });
                });
            }

            if (hasClients) {
                sections.push(function (output) {
                    output.push(translate('reports.export.analytics_clients', 'Client performance'));
                    output.push([
                        escapeCsvValue(translate('reports.export.analytics_client_label', 'Client')),
                        escapeCsvValue(translate('reports.export.analytics_client_average', 'Average dwell time (days)')),
                        escapeCsvValue(translate('reports.summary.records', 'Records'))
                    ].join(','));

                    analyticsSnapshot.clientPerformance.forEach(function (entry) {
                        output.push([
                            escapeCsvValue(entry.label),
                            escapeCsvValue(formatDecimal(entry.average)),
                            escapeCsvValue(formatInteger(entry.count))
                        ].join(','));
                    });
                });
            }

            sections.forEach(function (writer, index) {
                writer(lines);

                if (index < sections.length - 1) {
                    lines.push('');
                }
            });

            if (!lines.length) {
                showAlert('info', analyticsExportEmptyText);
                return;
            }

            var csvContent = lines.join('\r\n');
            var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            var link = document.createElement('a');

            link.href = URL.createObjectURL(blob);
            link.download = getExportFileName('csv');
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(link.href);
        }

        function fetchReports() {
            if (isLoading) {
                return;
            }

            isLoading = true;
            clearAlert();
            setTableMessage($tableBody, columnCount, tableLoadingText);
            setTableMessage($canceledTableBody, canceledColumnCount, tableLoadingText);
            $canceledSection.addClass('d-none');

            $.ajax({
                url: '../api/desembarques/list.php',
                method: 'GET',
                data: $filtersForm.serialize(),
                dataType: 'json'
            })
                .done(function (response) {
                    if (response && response.success) {
                        var data = Array.isArray(response.data) ? response.data : [];
                        renderRows(data);
                        if (pendingOpenAvisoId && Object.prototype.hasOwnProperty.call(recordsById, pendingOpenAvisoId)) {
                            var pendingRecord = recordsById[pendingOpenAvisoId];
                            pendingOpenAvisoId = '';
                            try {
                                var currentUrl = new URL(window.location.href);
                                currentUrl.searchParams.delete('open_aviso');
                                window.history.replaceState({}, document.title, currentUrl.pathname + (currentUrl.search ? currentUrl.search : '') + currentUrl.hash);
                            } catch (historyError) {
                                // The deep link is optional; failing to clean the URL must not block the modal.
                            }
                            window.setTimeout(function () {
                                openAvisoModal(pendingRecord);
                            }, 0);
                        }
                        updateSummary(response.summary || {});
                        updateCharts(data, response.summary || {});
                        updateDashboard(response.dashboard || {}, dashboardOptions);

                        var alertOptions = unreadAlertOptions ? Object.assign({}, unreadAlertOptions) : {};

                        if (!hasFetchedInitialReports) {
                            alertOptions.force = true;
                        }

                        updateUnreadObservacionesState(alertOptions);
                        unreadAlertOptions = null;
                        hasFetchedInitialReports = true;

                        if (pendingSuccessMessage) {
                            showAlert('success', pendingSuccessMessage);
                            pendingSuccessMessage = null;
                        } else if (!data.length) {
                            showAlert('info', translate('reports.alert.no_results', 'No records matched the selected filters.'));
                        }
                    } else {
                        renderRows([]);
                        updateSummary({});
                        updateCharts([], {});
                        updateDashboard({}, dashboardOptions);
                        pendingSuccessMessage = null;
                        showAlert('warning', response && response.message ? response.message : translate('reports.alert.load_error', 'Unable to retrieve the records.'));
                    }
                })
                .fail(function (jqXHR) {
                    renderRows([]);
                    updateSummary({});
                    updateCharts([], {});
                    updateDashboard({}, dashboardOptions);
                    pendingSuccessMessage = null;

                    var status = jqXHR ? jqXHR.status : 0;
                    var isSessionExpired = status === 401;
                    var isCsrfError = status === 419;
                    var message;

                    if (isSessionExpired) {
                        message = translate('common.session_expired', 'Your session has expired. Please sign in again.');
                    } else if (isCsrfError) {
                        message = jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.message
                            ? jqXHR.responseJSON.message
                            : translate('common.csrf_token_invalid', 'The security token has expired. Please refresh the page and try again.');
                    } else {
                        message = translate('reports.alert.load_error', 'Unable to retrieve the records.');
                    }

                    if (jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.message) {
                        message = jqXHR.responseJSON.message;
                    }

                    var alertType = isSessionExpired || isCsrfError ? 'warning' : 'danger';
                    showAlert(alertType, message);

                    if (isSessionExpired) {
                        window.setTimeout(function () {
                            window.location.href = 'login.php?status=expired&lang=' + encodeURIComponent(language);
                        }, 1500);
                    }
                })
                .always(function () {
                    isLoading = false;

                    if (pendingFetchAfterLoad) {
                        pendingFetchAfterLoad = false;
                        fetchReports();
                        return;
                    }

                    scheduleUnreadObservacionesCheck({
                        immediate: true,
                        delay: Math.min(1000, unreadObservacionesPollDelay)
                    });
                });
        }

        $filtersForm.on('submit', function (event) {
            event.preventDefault();
            fetchReports();
        });

        $resetButton.on('click', function () {
            if ($filtersForm.length && $filtersForm[0]) {
                $filtersForm[0].reset();
            }

            $filtersForm.find('select').each(function () {
                this.selectedIndex = 0;
            });

            fetchReports();
        });

        var handleAvisoButtonClick = function (event) {
            event.preventDefault();

            var recordId = $(this).attr('data-id') || '';

            if (!recordId) {
                showAlert('warning', avisoMissingRecordMessage);
                return;
            }

            var record = recordsById[recordId];

            if (record) {
                openAvisoModal(record);
            } else {
                showAlert('warning', avisoMissingRecordMessage);
            }
        };

        $tableBody.on('click', '[data-action="generate-aviso"]', handleAvisoButtonClick);
        $canceledTableBody.on('click', '[data-action="generate-aviso"]', handleAvisoButtonClick);

        if (canEditRecords) {
            var handleEditButtonClick = function (event) {
                event.preventDefault();

                var recordId = $(this).attr('data-id') || '';

                if (!recordId) {
                    return;
                }

                var record = recordsById[recordId];

                if (record) {
                    openEditModal(record);
                }
            };

            $tableBody.on('click', '[data-action="edit"]', handleEditButtonClick);
            $canceledTableBody.on('click', '[data-action="edit"]', handleEditButtonClick);
        }

        var handleAttachmentsButtonClick = function (event) {
            event.preventDefault();

            var recordId = ($(this).attr('data-id') || '').trim();

            if (!recordId) {
                return;
            }

            openAttachmentsModal(recordId);
        };

        $tableBody.on('click', '[data-action="view-attachments"]', handleAttachmentsButtonClick);
        $canceledTableBody.on('click', '[data-action="view-attachments"]', handleAttachmentsButtonClick);

        $tableBody.on('click', '[data-action="observaciones"]', function (event) {
            event.preventDefault();

            var recordId = $(this).attr('data-id') || '';

            if (!recordId) {
                return;
            }

            var record = recordsById[recordId];

            if (record) {
                openObservacionesModal(record);
            }
        });

        $canceledTableBody.on('click', '[data-action="observaciones"]', function (event) {
            event.preventDefault();

            var recordId = $(this).attr('data-id') || '';

            if (!recordId) {
                return;
            }

            var record = recordsById[recordId];

            if (record) {
                openObservacionesModal(record);
            }
        });

        var handlePedimentoHeadersClick = function (event) {
            event.preventDefault();

            if (!pedimentoDetailsModalElement) {
                return;
            }

            var recordId = ($(this).attr('data-record-id') || '').trim();

            if (!recordId || !Object.prototype.hasOwnProperty.call(recordsById, recordId)) {
                return;
            }

            var record = recordsById[recordId];

            if (record) {
                openPedimentoDetails(record, 'pedimentos');
            }
        };

        var handlePedimentoPartidasClick = function (event) {
            event.preventDefault();

            if (!pedimentoDetailsModalElement) {
                return;
            }

            var recordId = ($(this).attr('data-record-id') || '').trim();

            if (!recordId || !Object.prototype.hasOwnProperty.call(recordsById, recordId)) {
                return;
            }

            var record = recordsById[recordId];

            if (record) {
                openPedimentoDetails(record, 'partidas');
            }
        };

        $tableBody.on('click', '[data-action="show-pedimento-headers"]', handlePedimentoHeadersClick);
        $canceledTableBody.on('click', '[data-action="show-pedimento-headers"]', handlePedimentoHeadersClick);
        $tableBody.on('click', '[data-action="show-pedimento-partidas"]', handlePedimentoPartidasClick);
        $canceledTableBody.on('click', '[data-action="show-pedimento-partidas"]', handlePedimentoPartidasClick);

        if ($avisoExcelInput.length) {
            $avisoExcelInput.on('change', handleAvisoExcelChange);
        }

        if ($avisoProfileSelect.length) {
            $avisoProfileSelect.on('change', function () {
                var recordId = $avisoRecordIdInput.length ? String($avisoRecordIdInput.val() || '').trim() : '';
                var profileId = String($(this).val() || '').trim();
                var profile = findAvisoProfile(recordId, profileId);
                if (profile) {
                    applyAvisoProfile(profile, recordId);
                } else {
                    updateAvisoReadiness(recordId);
                }
            });
        }

        if ($avisoSaveProfileButton.length) {
            $avisoSaveProfileButton.on('click', function (event) {
                event.preventDefault();
                var recordId = $avisoRecordIdInput.length ? String($avisoRecordIdInput.val() || '').trim() : '';
                var record = recordId && Object.prototype.hasOwnProperty.call(recordsById, recordId) ? recordsById[recordId] : null;
                $avisoSaveProfileButton.prop('disabled', true);
                saveCurrentAvisoProfile(record).catch(function (error) {
                    showAvisoAlert(error && error.message ? error.message : (language === 'en' ? 'Unable to save the profile.' : 'No fue posible guardar el perfil.'));
                }).finally(function () {
                    $avisoSaveProfileButton.prop('disabled', false);
                });
            });
        }

        $avisoRigNameInput.add($avisoRigImoInput).add($avisoRigFieldInput).add($avisoRigAreaInput).add($avisoComitenteInput)
            .on('input change', function () {
                var recordId = $avisoRecordIdInput.length ? String($avisoRecordIdInput.val() || '').trim() : '';
                updateAvisoReadiness(recordId);
            });

        if ($avisoImporters.length) {
            $avisoImporters.on('input change', '[data-aviso-importer-key]', function () {
                var recordId = $avisoRecordIdInput.length ? String($avisoRecordIdInput.val() || '').trim() : '';
                var state = getAvisoState(recordId);
                if (!state || !Array.isArray(state.items)) {
                    return;
                }

                var key = String($(this).attr('data-aviso-importer-key') || '');
                var value = String($(this).val() || '').trim();
                buildAvisoImporterGroups(state.items).forEach(function (group) {
                    if (group.key !== key) {
                        return;
                    }
                    group.indexes.forEach(function (index) {
                        if (state.items[index]) {
                            state.items[index].importer_name = value;
                        }
                    });
                });

                if (canEditRecords) {
                    state.dirty = true;
                    setAvisoExcelStatus(
                        language === 'en' ? 'Changes not saved' : 'Cambios sin guardar',
                        'text-bg-warning'
                    );
                }
                $avisoItemsInput.val(buildAvisoItemsPreview(state.items));
                updateAvisoReadiness(recordId);
            });
        }

        if ($avisoPhotoUploadButton.length) {
            $avisoPhotoUploadButton.on('click', function (event) {
                event.preventDefault();
                if (!canEditRecords || avisoPhotoUploadBusy) {
                    return;
                }
                clearAvisoPhotoFeedback();
                var recordId = $avisoRecordIdInput.length ? String($avisoRecordIdInput.val() || '').trim() : '';
                var files = $avisoPhotoFilesInput.length && $avisoPhotoFilesInput[0].files
                    ? Array.prototype.slice.call($avisoPhotoFilesInput[0].files)
                    : [];
                if (!recordId || !files.length) {
                    showAvisoPhotoFeedback(language === 'en' ? 'Select at least one photograph.' : 'Selecciona al menos una fotografía.');
                    return;
                }

                avisoPhotoUploadBusy = true;
                $avisoPhotoUploadButton.prop('disabled', true);
                $avisoPhotoFilesInput.prop('disabled', true);
                uploadAvisoPhotos(recordId, files)
                    .then(function (uploaded) {
                        $avisoPhotoFilesInput.val('');
                        clearAvisoPhotoFeedback();
                        if (uploaded.length) {
                            showAlert('success', language === 'en'
                                ? uploaded.length + (uploaded.length === 1 ? ' photograph added to the annex.' : ' photographs added to the annex.')
                                : uploaded.length + ' fotografía(s) agregada(s) al anexo.');
                        }
                    })
                    .catch(function (error) {
                        showAvisoPhotoFeedback(error && error.message ? error.message : (language === 'en' ? 'Unable to upload the photographs.' : 'No fue posible subir las fotografías.'));
                    })
                    .finally(function () {
                        avisoPhotoUploadBusy = false;
                        $avisoPhotoUploadButton.prop('disabled', !canEditRecords);
                        $avisoPhotoFilesInput.prop('disabled', !canEditRecords);
                    });
            });
        }

        if ($avisoPhotosAvailable.length) {
            $avisoPhotosAvailable.on('click', '[data-action="aviso-photo-add"]', function (event) {
                event.preventDefault();
                var recordId = $avisoRecordIdInput.length ? String($avisoRecordIdInput.val() || '').trim() : '';
                var fileId = Number($(this).attr('data-file-id') || 0);
                if (!recordId || !fileId) {
                    return;
                }
                var attachment = getAvisoImageAttachments(recordId).find(function (candidate) {
                    return Number(candidate.id || 0) === fileId;
                });
                if (attachment) {
                    addAvisoPhotoFromAttachment(recordId, attachment);
                }
            });
        }

        if ($avisoPhotosSelected.length) {
            $avisoPhotosSelected.on('change', '[data-aviso-photo-item]', function () {
                var recordId = $avisoRecordIdInput.length ? String($avisoRecordIdInput.val() || '').trim() : '';
                var state = getAvisoState(recordId);
                var fileId = Number($(this).attr('data-aviso-photo-item') || 0);
                var photo = state && Array.isArray(state.photos) ? state.photos.find(function (candidate) {
                    return Number(candidate.file_id || 0) === fileId;
                }) : null;
                if (!photo) {
                    return;
                }
                var value = String($(this).val() || '');
                photo.item_source_row = null;
                photo.aviso_item_id = null;
                if (value.indexOf('row:') === 0) {
                    photo.item_source_row = Number(value.slice(4)) || null;
                } else if (value.indexOf('id:') === 0) {
                    photo.aviso_item_id = Number(value.slice(3)) || null;
                }
                markAvisoPhotoStateDirty(recordId);
            });

            $avisoPhotosSelected.on('input', '[data-aviso-photo-caption]', function () {
                var recordId = $avisoRecordIdInput.length ? String($avisoRecordIdInput.val() || '').trim() : '';
                var state = getAvisoState(recordId);
                var fileId = Number($(this).attr('data-aviso-photo-caption') || 0);
                var photo = state && Array.isArray(state.photos) ? state.photos.find(function (candidate) {
                    return Number(candidate.file_id || 0) === fileId;
                }) : null;
                if (!photo) {
                    return;
                }
                photo.caption = String($(this).val() || '');
                markAvisoPhotoStateDirty(recordId);
            });

            $avisoPhotosSelected.on('click', '[data-action^="aviso-photo-"]', function (event) {
                event.preventDefault();
                var action = String($(this).attr('data-action') || '');
                if (action === 'aviso-photo-add') {
                    return;
                }
                var recordId = $avisoRecordIdInput.length ? String($avisoRecordIdInput.val() || '').trim() : '';
                var state = getAvisoState(recordId);
                var fileId = Number($(this).attr('data-file-id') || 0);
                if (!state || !Array.isArray(state.photos) || !fileId) {
                    return;
                }
                var index = state.photos.findIndex(function (candidate) {
                    return Number(candidate.file_id || 0) === fileId;
                });
                if (index < 0) {
                    return;
                }

                if (action === 'aviso-photo-remove') {
                    state.photos.splice(index, 1);
                } else if (action === 'aviso-photo-up' && index > 0) {
                    var previous = state.photos[index - 1];
                    state.photos[index - 1] = state.photos[index];
                    state.photos[index] = previous;
                } else if (action === 'aviso-photo-down' && index < state.photos.length - 1) {
                    var next = state.photos[index + 1];
                    state.photos[index + 1] = state.photos[index];
                    state.photos[index] = next;
                } else {
                    return;
                }

                state.photos.forEach(function (photo, photoIndex) { photo.sort_order = photoIndex + 1; });
                markAvisoPhotoStateDirty(recordId);
                renderAvisoPhotos(recordId);
            });
        }

        if ($avisoForm.length) {
            $avisoForm.on('submit', function (event) {
                event.preventDefault();
                resetAvisoAlert();

                var recordId = $avisoRecordIdInput.length ? String($avisoRecordIdInput.val() || '').trim() : '';
                if (!recordId || !Object.prototype.hasOwnProperty.call(recordsById, recordId) || !recordsById[recordId]) {
                    showAvisoAlert(avisoMissingRecordMessage);
                    return;
                }

                var record = recordsById[recordId];
                var state = getAvisoState(recordId);
                if (!state || !Array.isArray(state.items) || !state.items.length) {
                    showAvisoAlert(language === 'en'
                        ? 'This legacy record has no structured source data yet. Open Advanced options and upload its Excel once.'
                        : 'Este registro legacy todavía no tiene datos fuente estructurados. Abre Opciones avanzadas y carga su Excel una sola vez.');
                    openAvisoAdvancedOptions();
                    return;
                }

                if (state.status && state.status.can_generate === false) {
                    showAvisoAlert(language === 'en'
                        ? 'This notice is CANCELLED. Reopen it as Draft from the case file before issuing a new version.'
                        : 'Este aviso está CANCELADO. Reábrelo a Borrador desde el expediente antes de emitir una nueva versión.');
                    return;
                }

                var missingImporters = findMissingAvisoImporters(state.items);
                if (missingImporters.length) {
                    var missingLabel = missingImporters.map(function (group) {
                        return (group.clave || (language === 'en' ? 'NO CODE' : 'SIN CLAVE')) + (group.pedimento ? ' · ' + group.pedimento : '');
                    }).join(', ');
                    showAvisoAlert((language === 'en'
                        ? 'Complete the importer/legal business name for: '
                        : 'Completa la razón social del importador para: ') + missingLabel);
                    openAvisoAdvancedOptions();
                    return;
                }

                var readinessIssues = getAvisoReadinessIssues(recordId);
                if (readinessIssues.length) {
                    showAvisoAlert((language === 'en' ? 'Complete before generating: ' : 'Completa antes de generar: ') + readinessIssues.join(', ') + '.');
                    updateAvisoReadiness(recordId);
                    return;
                }

                var formData = collectAvisoFormData();
                var $generateButton = $('#report-aviso-generate');
                $generateButton.prop('disabled', true);

                saveAvisoData(formData, record)
                    .then(function () {
                        return generateAvisoPdf(formData, record);
                    })
                    .then(function (pdfResult) {
                        if (!pdfResult || !pdfResult.blob) {
                            return false;
                        }

                        return archiveAvisoVersion(pdfResult, record)
                            .then(function () {
                                if (window.AvisoPdfGenerator && typeof window.AvisoPdfGenerator.download === 'function') {
                                    window.AvisoPdfGenerator.download(pdfResult);
                                }
                                return true;
                            })
                            .catch(function (archiveError) {
                                if (window.AvisoPdfGenerator && typeof window.AvisoPdfGenerator.download === 'function') {
                                    window.AvisoPdfGenerator.download(pdfResult);
                                }
                                if (window.console && typeof window.console.error === 'function') {
                                    console.error('[aviso] archive', archiveError);
                                }
                                showAvisoAlert((language === 'en'
                                    ? 'The PDF was generated and downloaded, but it could not be archived: '
                                    : 'El PDF se generó y se descargó, pero no pudo archivarse en el historial: ')
                                    + (archiveError && archiveError.message ? archiveError.message : ''));
                                return false;
                            });
                    })
                    .then(function (success) {
                        if (success && avisoModal) {
                            avisoModal.hide();
                        }
                    })
                    .catch(function (error) {
                        if (window.console && typeof window.console.error === 'function') {
                            console.error('[aviso] save/generate', error);
                        }
                        showAvisoAlert(error && error.message ? error.message : (language === 'en' ? 'Unable to prepare the notice.' : 'No fue posible preparar el aviso.'));
                    })
                    .finally(function () {
                        $generateButton.prop('disabled', false);
                    });
            });
        }

        if ($editClienteSelect.length) {
            $editClienteSelect.on('change', handleEditClienteSelectionChange);
        }

        if ($editAttachmentsInput.length) {
            $editAttachmentsInput.on('change', handleEditAttachmentsChange);
        }

        if ($editAttachmentsUploadButton.length) {
            $editAttachmentsUploadButton.on('click', function (event) {
                event.preventDefault();
                uploadPendingAttachments();
            });
        }

        if ($editAttachmentsList.length) {
            $editAttachmentsList.on('click', '[data-action="delete-attachment"]', function (event) {
                event.preventDefault();

                if (!canEditRecords) {
                    return;
                }

                var attachmentId = $(this).attr('data-attachment-id') || '';
                var recordId = currentEditRecordId || '';

                if (!attachmentId || !recordId) {
                    return;
                }

                handleAttachmentDeletion(recordId, attachmentId, $(this));
            });
        }

        if ($editForm.length) {
            $editForm.on('submit', function (event) {
                event.preventDefault();

                if (!canEditRecords) {
                    return;
                }

                clearEditFormErrors();
                clearEditFormAlert();
                clearEditAttachmentsFeedback();

                if ($editSubmitButton.length) {
                    $editSubmitButton.prop('disabled', true);
                }

                if ($editSpinner.length) {
                    $editSpinner.removeClass('d-none');
                }

                var formData = new FormData($editForm[0]);

                $.ajax({
                    url: '../api/desembarques/update.php',
                    method: 'POST',
                    data: formData,
                    dataType: 'json',
                    processData: false,
                    contentType: false
                })
                    .done(function (response) {
                        if (response && response.success) {
                            if (editModal) {
                                editModal.hide();
                            }

                            pendingSuccessMessage = updateCompletedMessage;

                            if (window.Swal && typeof window.Swal.fire === 'function') {
                                window.Swal.fire({
                                    icon: 'success',
                                    text: updateCompletedMessage
                                });
                            }

                            fetchReports();
                        } else {
                            var warningMessage = response && response.message
                                ? response.message
                                : translate('reports.alert.update_error', 'Unable to update the record.');

                            showEditFormAlert('warning', warningMessage);

                            if (response && response.errors) {
                                displayEditFormErrors(response.errors);

                                if (response.errors.attachments) {
                                    showEditAttachmentsFeedback(response.errors.attachments);
                                }
                            }
                        }
                    })
                    .fail(function (jqXHR) {
                        pendingSuccessMessage = null;

                        var status = jqXHR ? jqXHR.status : 0;
                        var errors = jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.errors
                            ? jqXHR.responseJSON.errors
                            : null;
                        var message = translate('reports.alert.update_error', 'Unable to update the record.');

                        if (status === 422) {
                            message = jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.message
                                ? jqXHR.responseJSON.message
                                : translate('reports.alert.update_validation', 'Please review the highlighted fields.');
                        } else if (status === 401) {
                            message = translate('common.session_expired', 'Your session has expired. Please sign in again.');
                        } else if (status === 419) {
                            message = jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.message
                                ? jqXHR.responseJSON.message
                                : translate('common.csrf_token_invalid', 'The security token has expired. Please refresh the page and try again.');
                        } else if (jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.message) {
                            message = jqXHR.responseJSON.message;
                        }

                        var alertType = status === 422 || status === 419 ? 'warning' : 'danger';
                        showEditFormAlert(alertType, message);

                        if (errors) {
                            displayEditFormErrors(errors);

                            if (errors.attachments) {
                                showEditAttachmentsFeedback(errors.attachments);
                            }
                        }

                        if (status === 401) {
                            window.setTimeout(function () {
                                window.location.href = 'login.php?status=expired&lang=' + encodeURIComponent(language);
                            }, 1500);
                        }
                    })
                    .always(function () {
                        if ($editSubmitButton.length) {
                            $editSubmitButton.prop('disabled', false);
                        }

                        if ($editSpinner.length) {
                            $editSpinner.addClass('d-none');
                        }
                    });
            });
        }

        if ($observacionesList.length && canAddObservaciones) {
            $observacionesList.on('click', '[data-action="edit-observacion"]', function (event) {
                event.preventDefault();

                var $button = $(this);
                var observationId = ($button.attr('data-observation-id') || '').trim();
                var recordId = ($button.attr('data-record-id') || '').trim();

                if (!recordId) {
                    recordId = String(currentObservacionesRecordId || '');
                }

                if (!recordId || !observationId) {
                    return;
                }

                var observation = findObservationInCache(recordId, observationId);

                if (!observation) {
                    return;
                }

                clearObservacionesErrors();
                clearObservacionesAlert();
                setObservationEditingState(recordId, observation);
            });
        }

        if ($observacionesForm.length && canAddObservaciones) {
            $observacionesForm.on('submit', function (event) {
                event.preventDefault();

                clearObservacionesErrors();
                clearObservacionesAlert();

                if ($observacionesSaveButton.length) {
                    $observacionesSaveButton.prop('disabled', true);
                }

                if ($observacionesSpinner.length) {
                    $observacionesSpinner.removeClass('d-none');
                }

                $.ajax({
                    url: '../api/desembarques/update_observaciones.php',
                    method: 'POST',
                    data: $observacionesForm.serialize(),
                    dataType: 'json'
                })
                    .done(function (response) {
                        if (response && response.success) {
                            var successMessage = updateCompletedMessage;
                            var recordIdValue = ($observacionesForm.find('[name="id"]').val() || '').trim();
                            var observationData = response.observation || null;
                            var totalCount = typeof response.count !== 'undefined' ? response.count : null;
                            var modeValue = typeof response.mode === 'string' ? response.mode.toLowerCase() : '';
                            var isUpdate = modeValue === 'updated';

                            if (recordIdValue) {
                                if (observationData) {
                                    if (isUpdate) {
                                        replaceObservation(recordIdValue, observationData, totalCount);
                                    } else {
                                        appendObservation(recordIdValue, observationData, totalCount);
                                    }
                                } else if (totalCount !== null) {
                                    syncRecordObservationCount(recordIdValue, totalCount);
                                    markObservacionesAsRead(recordIdValue, {});
                                }
                            }

                            clearObservationEditingState();

                            if ($observacionesTextarea.length) {
                                $observacionesTextarea.val('');
                                $observacionesTextarea.trigger('input');
                            }

                            if (observacionesModal) {
                                observacionesModal.hide();
                            }

                            if (window.Swal && typeof window.Swal.fire === 'function') {
                                window.Swal.fire({
                                    icon: 'success',
                                    text: successMessage
                                }).then(function () {
                                    showAlert('success', successMessage);
                                });
                            } else {
                                showAlert('success', successMessage);
                            }
                        } else {
                            var warningMessage = response && response.message
                                ? response.message
                                : translate('reports.alert.observaciones_update_error', 'Unable to update the observations.');

                            showObservacionesAlert('warning', warningMessage);

                            if (response && response.errors) {
                                displayObservacionesErrors(response.errors);
                            }
                        }
                    })
                    .fail(function (jqXHR) {
                        var status = jqXHR ? jqXHR.status : 0;
                        var errors = jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.errors
                            ? jqXHR.responseJSON.errors
                            : null;
                        var message = translate('reports.alert.observaciones_update_error', 'Unable to update the observations.');

                        if (status === 422) {
                            message = jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.message
                                ? jqXHR.responseJSON.message
                                : translate('reports.alert.observaciones_update_validation', 'Please review the observations.');
                        } else if (status === 401) {
                            message = translate('common.session_expired', 'Your session has expired. Please sign in again.');
                        } else if (status === 419) {
                            message = jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.message
                                ? jqXHR.responseJSON.message
                                : translate('common.csrf_token_invalid', 'The security token has expired. Please refresh the page and try again.');
                        } else if (jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.message) {
                            message = jqXHR.responseJSON.message;
                        }

                        var alertType = status === 422 || status === 419 ? 'warning' : 'danger';
                        showObservacionesAlert(alertType, message);

                        if (errors) {
                            displayObservacionesErrors(errors);
                        }

                        if (status === 401) {
                            window.setTimeout(function () {
                                window.location.href = 'login.php?status=expired&lang=' + encodeURIComponent(language);
                            }, 1500);
                        }
                    })
                    .always(function () {
                        if ($observacionesSaveButton.length) {
                            $observacionesSaveButton.prop('disabled', false);
                        }

                        if ($observacionesSpinner.length) {
                            $observacionesSpinner.addClass('d-none');
                        }
                    });
            });
        }

        if ($exportAnalyticsButton.length) {
            $exportAnalyticsButton.on('click', function (event) {
                event.preventDefault();
                exportAnalyticsSummary();
            });
        }

        if ($exportPdfButton.length) {
            $exportPdfButton.on('click', function (event) {
                event.preventDefault();
                exportToPdf();
            });
        }

        if ($exportExcelButton.length) {
            $exportExcelButton.on('click', function (event) {
                event.preventDefault();
                exportToExcel();
            });
        }

        if (typeof document !== 'undefined' && typeof document.addEventListener === 'function') {
            document.addEventListener('visibilitychange', function () {
                if (isDocumentVisible()) {
                    if (isUnreadObservacionesStreamSupported()) {
                        if (!isUnreadObservacionesStreamActive()) {
                            startUnreadObservacionesStream();
                        } else if (!isUnreadObservacionesStreamHealthy()) {
                            scheduleUnreadObservacionesStreamReconnect({ delay: 0, requireVisibility: true });
                        }
                    }

                    scheduleUnreadObservacionesCheck({ immediate: true, delay: 0 });
                }
            });
        }

        if (typeof window !== 'undefined' && typeof window.addEventListener === 'function') {
            window.addEventListener('focus', function () {
                if (isUnreadObservacionesStreamSupported()) {
                    if (!isUnreadObservacionesStreamActive()) {
                        startUnreadObservacionesStream();
                    } else if (!isUnreadObservacionesStreamHealthy()) {
                        scheduleUnreadObservacionesStreamReconnect({ delay: 0 });
                    }
                }

                scheduleUnreadObservacionesCheck({ immediate: true, delay: 0 });
            });
        }

        if (isUnreadObservacionesStreamSupported()) {
            startUnreadObservacionesStream();
        }

        fetchReports();
    });
})(jQuery);
