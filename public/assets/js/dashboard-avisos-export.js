(function () {
    'use strict';

    var config = window.DashboardAvisosConfig || {};
    var labels = config.labels || {};
    var language = config.language || 'es';
    var app = document.getElementById('dashboard-executive-app');
    if (!app) return;

    var exportButtons = Array.prototype.slice.call(app.querySelectorAll('[data-dashboard-export]'));
    var printFilters = app.querySelector('[data-dashboard-print-filters]');
    var printGenerated = app.querySelector('[data-dashboard-print-generated]');
    var numberFormatter = new Intl.NumberFormat(language === 'en' ? 'en-US' : 'es-MX', { maximumFractionDigits: 3 });
    var dateTimeFormatter = new Intl.DateTimeFormat(language === 'en' ? 'en-US' : 'es-MX', {
        dateStyle: 'medium', timeStyle: 'short'
    });

    function localized(english, spanish) {
        return language === 'en' ? english : spanish;
    }

    function exportStatusLabel(id, fallback) {
        var map = {
            draft: labels.draft || localized('Draft', 'Borrador'),
            issued: labels.issued || localized('Issued', 'Emitido'),
            presented: labels.presented || localized('Presented', 'Presentado'),
            replaced: labels.replaced || localized('Replaced', 'Reemplazado'),
            cancelled: labels.cancelled || localized('Cancelled', 'Cancelado')
        };
        return map[id] || fallback || id;
    }

    function exportOriginLabel(id, fallback) {
        if (id === 'historical') return labels.historical || localized('Historical', 'Histórico');
        if (id === 'system') return labels.system || localized('System', 'Sistema');
        return fallback || id;
    }

    function exportAgingLabel(id, fallback) {
        var map = {
            '0_30': localized('0–30 days', '0 a 30 días'),
            '31_60': localized('31–60 days', '31 a 60 días'),
            '61_90': localized('61–90 days', '61 a 90 días'),
            '91_plus': localized('91+ days', '91 días o más'),
            unknown: localized('Unknown date', 'Fecha desconocida')
        };
        return map[id] || fallback || id;
    }

    function exportDimensionLabel(value, dimension) {
        var normalized = String(value || '');
        if (dimension === 'client' && normalized === 'Sin cliente') return localized('No client assigned', 'Sin cliente');
        if (dimension === 'rig' && normalized === 'Sin Rig') return localized('No rig assigned', 'Sin Rig');
        return normalized || '—';
    }

    function exportRow(fields) {
        var row = {};
        fields.forEach(function (field) {
            row[localized(field[0], field[1])] = field[2];
        });
        return row;
    }

    function runtime() {
        return window.DashboardAvisosRuntime || null;
    }

    function payload() {
        var rt = runtime();
        return rt && typeof rt.getPayload === 'function' ? rt.getPayload() : null;
    }

    function integrityValid() {
        var rt = runtime();
        return Boolean(rt && typeof rt.isIntegrityValid === 'function' && rt.isIntegrityValid());
    }

    function setButtons(enabled) {
        exportButtons.forEach(function (button) { button.disabled = !enabled; });
    }

    function feedback(message, isError) {
        var old = document.querySelector('.dashboard-export-feedback');
        if (old) old.remove();
        var node = document.createElement('div');
        node.className = 'dashboard-export-feedback' + (isError ? ' is-error' : '');
        node.textContent = message;
        document.body.appendChild(node);
        window.setTimeout(function () { if (node.isConnected) node.remove(); }, 4200);
    }

    function requirePayload() {
        var data = payload();
        if (!data || !integrityValid()) {
            throw new Error(labels.exportUnavailable || (language === 'en' ? 'Load a valid dashboard before exporting.' : 'Carga un Dashboard válido antes de exportar.'));
        }
        return data;
    }

    function activeFilters(data) {
        var filters = (data && data.filters) || {};
        var catalog = (data && data.filter_catalog) || {};
        var parts = [];
        if (filters.date_from) parts.push((language === 'en' ? 'From' : 'Desde') + ': ' + filters.date_from);
        if (filters.date_to) parts.push((language === 'en' ? 'To' : 'Hasta') + ': ' + filters.date_to);
        if (filters.client_id) {
            var client = (catalog.clients || []).find(function (row) { return Number(row.id) === Number(filters.client_id); });
            parts.push((language === 'en' ? 'Client' : 'Cliente') + ': ' + (client ? client.label : filters.client_id));
        }
        if (filters.rig_name) parts.push('Rig: ' + filters.rig_name);
        if (filters.aviso_status && filters.aviso_status !== 'all') parts.push((language === 'en' ? 'Status' : 'Estado') + ': ' + filters.aviso_status);
        if (filters.origin && filters.origin !== 'all') parts.push((language === 'en' ? 'Origin' : 'Origen') + ': ' + filters.origin);
        if (filters.record_scope && filters.record_scope !== 'production') parts.push((language === 'en' ? 'Scope' : 'Alcance') + ': ' + filters.record_scope);
        return parts.length ? parts.join(' · ') : (language === 'en' ? 'All production data' : 'Toda la producción');
    }

    function nowText() {
        return dateTimeFormatter.format(new Date());
    }

    function updatePrintMeta(data) {
        if (printFilters) printFilters.textContent = (labels.reportFilters || (language === 'en' ? 'Applied filters' : 'Filtros aplicados')) + ': ' + activeFilters(data);
        if (printGenerated) printGenerated.textContent = (language === 'en' ? 'Generated' : 'Generado') + ': ' + nowText();
    }

    function safeFilename(prefix, extension) {
        var stamp = new Date().toISOString().replace(/[-:]/g, '').replace(/T/, '-').slice(0, 13);
        return prefix + '-' + stamp + '.' + extension;
    }

    function downloadBlob(blob, filename) {
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
    }

    function text(page, value, x, y, font, size, color, maxWidth) {
        var string = String(value === null || value === undefined ? '' : value);
        if (!maxWidth) {
            page.drawText(string, { x: x, y: y, font: font, size: size, color: color });
            return y - size - 4;
        }
        var words = string.split(/\s+/);
        var line = '';
        var lines = [];
        words.forEach(function (word) {
            var test = line ? line + ' ' + word : word;
            if (font.widthOfTextAtSize(test, size) > maxWidth && line) {
                lines.push(line); line = word;
            } else line = test;
        });
        if (line) lines.push(line);
        lines.forEach(function (row, index) {
            page.drawText(row, { x: x, y: y - index * (size + 3), font: font, size: size, color: color });
        });
        return y - lines.length * (size + 3) - 2;
    }

    function canvasPngBytes(id) {
        var canvas = document.getElementById(id);
        if (!canvas || !canvas.width || !canvas.height) return null;
        var dataUrl = canvas.toDataURL('image/png');
        var base64 = dataUrl.split(',')[1] || '';
        if (!base64) return null;
        var binary = atob(base64);
        var bytes = new Uint8Array(binary.length);
        for (var i = 0; i < binary.length; i += 1) bytes[i] = binary.charCodeAt(i);
        return bytes;
    }

    async function exportPdf() {
        var data = requirePayload();
        if (!window.PDFLib || !window.PDFLib.PDFDocument) throw new Error(language === 'en' ? 'PDF-lib is not available.' : 'PDF-lib no está disponible.');

        var PDFLib = window.PDFLib;
        var pdf = await PDFLib.PDFDocument.create();
        var regular = await pdf.embedFont(PDFLib.StandardFonts.Helvetica);
        var bold = await pdf.embedFont(PDFLib.StandardFonts.HelveticaBold);
        var ink = PDFLib.rgb(0.09, 0.13, 0.20);
        var muted = PDFLib.rgb(0.38, 0.43, 0.50);
        var accent = PDFLib.rgb(0.84, 0.08, 0.22);
        var green = PDFLib.rgb(0.10, 0.53, 0.33);
        var blue = PDFLib.rgb(0.06, 0.43, 0.58);
        var light = PDFLib.rgb(0.96, 0.97, 0.98);
        var pageSize = [841.89, 595.28]; // A4 landscape
        var margin = 34;

        function addPage(title) {
            var page = pdf.addPage(pageSize);
            page.drawText('GRUPO GEREZ', { x: margin, y: pageSize[1] - 32, font: bold, size: 10, color: accent });
            page.drawText(title, { x: margin, y: pageSize[1] - 54, font: bold, size: 20, color: ink });
            return page;
        }

        // Page 1: executive summary
        var reportTitle = labels.reportTitle || localized('Executive Unloading Notice Report', 'Reporte Ejecutivo de Avisos de Desembarque');
        var page = addPage(reportTitle);
        var y = pageSize[1] - 76;
        y = text(page, activeFilters(data), margin, y, regular, 8.5, muted, pageSize[0] - margin * 2);
        y = text(page, (language === 'en' ? 'Generated' : 'Generado') + ': ' + nowText(), margin, y, regular, 8, muted);
        y -= 8;

        var kpis = data.kpis || {};
        var cards = [
            [labels.avisosTotal || localized('Total notices', 'Avisos totales'), kpis.avisos_total || 0, accent],
            [labels.presented || localized('Presented', 'Presentados'), kpis.avisos_presented || 0, green],
            [labels.storedPieces || (language === 'en' ? 'Pieces in storage' : 'Piezas en almacén'), kpis.pieces_stored || 0, blue],
            [labels.exportedPieces || localized('Released pieces', 'Piezas exportadas'), kpis.pieces_exported || 0, green],
            [labels.partialRows || (language === 'en' ? 'Partially released merchandise' : 'Mercancías parciales'), kpis.rows_partial || 0, PDFLib.rgb(0.85, 0.45, 0.03)],
            [labels.pdfVersions || localized('PDF versions', 'Versiones PDF'), kpis.pdf_versions || 0, accent]
        ];
        var gap = 8;
        var cardW = (pageSize[0] - margin * 2 - gap * 5) / 6;
        cards.forEach(function (card, index) {
            var x = margin + index * (cardW + gap);
            page.drawRectangle({ x: x, y: y - 70, width: cardW, height: 70, color: light, borderColor: PDFLib.rgb(.85,.87,.90), borderWidth: .7 });
            text(page, card[0], x + 8, y - 15, regular, 7.2, muted, cardW - 16);
            page.drawText(numberFormatter.format(Number(card[1]) || 0), { x: x + 8, y: y - 53, font: bold, size: 19, color: card[2] });
        });
        y -= 88;

        page.drawText(language === 'en' ? 'Executive attention' : 'Atención ejecutiva', { x: margin, y: y, font: bold, size: 12, color: ink });
        y -= 18;
        var attention = [
            [labels.draft || localized('Draft', 'Borrador'), kpis.avisos_draft || 0],
            [labels.issued || localized('Issued', 'Emitido'), kpis.avisos_issued || 0],
            [labels.partialRows || (language === 'en' ? 'Partially released merchandise' : 'Mercancías parciales'), kpis.rows_partial || 0],
            [labels.over90 || (language === 'en' ? 'Pieces stored for 90+ days' : 'Piezas +90 días'), ((data.series || {}).aging || []).filter(function (r) { return r.id === '91_plus'; }).reduce(function (_, r) { return Number(r.pieces_stored || 0); }, 0)]
        ];
        attention.forEach(function (row, idx) {
            var x = margin + (idx % 2) * 250;
            var yy = y - Math.floor(idx / 2) * 34;
            page.drawText(row[0] + ':', { x: x, y: yy, font: regular, size: 9, color: muted });
            page.drawText(numberFormatter.format(Number(row[1]) || 0), { x: x + 150, y: yy, font: bold, size: 10, color: ink });
        });
        y -= 82;

        var cycles = data.cycle_times || {};
        page.drawText(language === 'en' ? 'Cycle times' : 'Tiempos de ciclo', { x: margin, y: y, font: bold, size: 12, color: ink });
        y -= 20;
        text(page, (labels.landingNotice || localized('Unloading → Notice', 'Desembarque → Aviso')) + ': ' + (cycles.landing_to_notice_days === null ? '—' : cycles.landing_to_notice_days + ' ' + (labels.days || localized('days', 'días'))), margin, y, regular, 9, ink);
        text(page, (labels.noticePresented || localized('Notice → Presented', 'Aviso → Presentado')) + ': ' + (cycles.notice_to_presented_days === null ? '—' : cycles.notice_to_presented_days + ' ' + (labels.days || localized('days', 'días'))), margin + 300, y, regular, 9, ink);

        // Page 2 charts
        page = addPage(language === 'en' ? 'Executive visual analysis' : 'Análisis visual ejecutivo');
        var charts = [
            ['dashboard-chart-monthly', margin, 275, 490, 235, labels.activityTitle || localized('Notice activity', 'Actividad de avisos')],
            ['dashboard-chart-status', 545, 315, 250, 190, labels.statusTitle || localized('Document status', 'Estado documental')],
            ['dashboard-chart-pieces', margin, 42, 340, 195, labels.piecesTitle || (language === 'en' ? 'Merchandise balance' : 'Balance de mercancía')],
            ['dashboard-chart-aging', 430, 42, 365, 195, labels.agingTitle || (language === 'en' ? 'Storage aging' : 'Antigüedad en almacén')]
        ];
        for (var c = 0; c < charts.length; c += 1) {
            var spec = charts[c];
            var bytes = canvasPngBytes(spec[0]);
            page.drawText(spec[5], { x: spec[1], y: spec[2] + spec[4] + 10, font: bold, size: 10, color: ink });
            if (bytes) {
                var image = await pdf.embedPng(bytes);
                var scale = Math.min(spec[3] / image.width, spec[4] / image.height);
                var w = image.width * scale;
                var h = image.height * scale;
                page.drawImage(image, { x: spec[1] + (spec[3] - w) / 2, y: spec[2] + (spec[4] - h) / 2, width: w, height: h });
            } else {
                page.drawRectangle({ x: spec[1], y: spec[2], width: spec[3], height: spec[4], color: light });
            }
        }

        // Page 3 rankings + recent notices
        page = addPage(language === 'en' ? 'Activity and recent notices' : 'Actividad y avisos recientes');
        function drawRanking(title, rows, x, topY, width, dimension) {
            page.drawText(title, { x: x, y: topY, font: bold, size: 11, color: ink });
            var yy = topY - 18;
            (rows || []).slice(0, 8).forEach(function (row) {
                yy = text(page, exportDimensionLabel(row.label, dimension), x, yy, regular, 8, ink, width - 150);
                page.drawText(String(row.avisos || 0) + ' ' + (labels.avisos || localized('notices', 'Avisos')), { x: x + width - 135, y: yy + 11, font: bold, size: 8, color: accent });
                yy -= 5;
            });
            return yy;
        }
        drawRanking(labels.clientsTitle || localized('Activity by client', 'Actividad por cliente'), (data.rankings || {}).clients || [], margin, pageSize[1] - 86, 365, 'client');
        drawRanking(labels.rigsTitle || localized('Activity by rig / project', 'Actividad por Rig / Proyecto'), (data.rankings || {}).rigs || [], 440, pageSize[1] - 86, 355, 'rig');

        var recent = data.recent_avisos || [];
        var tableY = 250;
        page.drawText(labels.recentTitle || localized('Recent notices', 'Avisos recientes'), { x: margin, y: tableY, font: bold, size: 11, color: ink });
        tableY -= 18;
        var cols = [margin, 115, 185, 350, 535, 635, 715];
        var heads = [
            labels.notice || localized('Notice', 'Aviso'),
            labels.date || localized('Date', 'Fecha'),
            labels.clientCol || localized('Client', 'Cliente'),
            labels.rigCol || 'Rig',
            labels.originCol || localized('Origin', 'Origen'),
            labels.statusCol || localized('Status', 'Estado')
        ];
        heads.forEach(function (head, index) { page.drawText(head, { x: cols[index], y: tableY, font: bold, size: 7.5, color: muted }); });
        tableY -= 13;
        recent.slice(0, 10).forEach(function (row) {
            var values = [
                row.notice_number || row.document_code || '',
                row.metric_date || '',
                row.client_name || '',
                row.rig_name || '',
                exportOriginLabel(row.origin, row.origin || ''),
                exportStatusLabel(row.aviso_status, row.aviso_status || '')
            ];
            values.forEach(function (value, index) {
                var max = index === 2 ? 150 : (index === 3 ? 165 : 80);
                var val = String(value || '');
                while (regular.widthOfTextAtSize(val, 7) > max && val.length > 4) val = val.slice(0, -2);
                if (val !== String(value || '')) val += '…';
                page.drawText(val, { x: cols[index], y: tableY, font: regular, size: 7, color: ink });
            });
            tableY -= 15;
        });

        var pages = pdf.getPages();
        pages.forEach(function (pdfPage, idx) {
            pdfPage.drawText((idx + 1) + ' / ' + pages.length, { x: pageSize[0] - 60, y: 18, font: regular, size: 7, color: muted });
            pdfPage.drawText(localized('Unloading Registry · Grupo Gerez', 'Desembarques · Grupo Gerez'), { x: margin, y: 18, font: regular, size: 7, color: muted });
        });
        pdf.setTitle(reportTitle);
        pdf.setSubject(activeFilters(data));
        pdf.setCreator('Desembarques - Grupo Gerez');
        var result = await pdf.save();
        downloadBlob(new Blob([result], { type: 'application/pdf' }), safeFilename(localized('executive-unloading-notice-report', 'reporte-ejecutivo-desembarques'), 'pdf'));
    }

    function exportExcel() {
        var data = requirePayload();
        if (!window.XLSX) throw new Error(language === 'en' ? 'SheetJS is not available.' : 'SheetJS no está disponible.');
        var XLSX = window.XLSX;
        var wb = XLSX.utils.book_new();
        var kpis = data.kpis || {};
        var summary = [
            [localized('Report', 'Reporte'), labels.reportTitle || localized('Executive Unloading Notice Report', 'Reporte Ejecutivo de Avisos de Desembarque')],
            [localized('Generated', 'Generado'), nowText()],
            [localized('Filters', 'Filtros'), activeFilters(data)],
            [],
            ['KPI', localized('Value', 'Valor')],
            [labels.avisosTotal || localized('Total notices', 'Avisos totales'), kpis.avisos_total || 0],
            [labels.historical || (language === 'en' ? 'Historical' : 'Históricos'), kpis.avisos_historical || 0],
            [labels.system || localized('System generated', 'Sistema'), kpis.avisos_system || 0],
            [labels.presented || localized('Presented', 'Presentados'), kpis.avisos_presented || 0],
            [labels.issued || localized('Issued', 'Emitidos'), kpis.avisos_issued || 0],
            [labels.draft || localized('Draft', 'Borradores'), kpis.avisos_draft || 0],
            [labels.cancelled || localized('Cancelled', 'Cancelados'), kpis.avisos_cancelled || 0],
            [labels.pdfVersions || localized('PDF versions', 'Versiones PDF'), kpis.pdf_versions || 0],
            [labels.rows || localized('Merchandise lines', 'Renglones'), kpis.merchandise_rows || 0],
            [labels.pieces || localized('Original pieces', 'Piezas originales'), kpis.pieces_original || 0],
            [labels.exportedPieces || localized('Released pieces', 'Piezas exportadas'), kpis.pieces_exported || 0],
            [labels.storedPieces || (language === 'en' ? 'Pieces in storage' : 'Piezas en almacén'), kpis.pieces_stored || 0],
            [labels.partialRows || (language === 'en' ? 'Partially released merchandise' : 'Mercancías parciales'), kpis.rows_partial || 0],
            [labels.pedimentos || (language === 'en' ? 'Unique customs entries' : 'Pedimentos únicos'), kpis.pedimentos_unique || 0],
            [labels.clients || localized('Active clients', 'Clientes activos'), kpis.clients_active || 0],
            [labels.rigs || localized('Active rigs', 'Rigs activos'), kpis.rigs_active || 0]
        ];
        var ws = XLSX.utils.aoa_to_sheet(summary);
        ws['!cols'] = [{ wch: 32 }, { wch: 72 }];
        XLSX.utils.book_append_sheet(wb, ws, localized('Summary', 'Resumen'));

        function appendJson(name, rows, cols) {
            var sheet = XLSX.utils.json_to_sheet(rows || []);
            if (cols) sheet['!cols'] = cols.map(function (wch) { return { wch: wch }; });
            if ((rows || []).length) sheet['!autofilter'] = { ref: sheet['!ref'] };
            XLSX.utils.book_append_sheet(wb, sheet, name.slice(0, 31));
        }
        var statusRows = ((data.breakdowns || {}).status || []).map(function (row) {
            return exportRow([
                ['Status', 'Estado', exportStatusLabel(row.id, row.label)],
                ['Notices', 'Avisos', row.value || 0]
            ]);
        });
        var originRows = ((data.breakdowns || {}).origin || []).map(function (row) {
            return exportRow([
                ['Origin', 'Origen', exportOriginLabel(row.id, row.label)],
                ['Notices', 'Avisos', row.value || 0]
            ]);
        });
        var monthlyRows = ((data.series || {}).monthly || []).map(function (row) {
            return exportRow([
                ['Month', 'Mes', row.month || ''],
                ['Notices', 'Avisos', row.avisos || 0],
                ['Historical', 'Históricos', row.historical || 0],
                ['System generated', 'Sistema', row.system || 0],
                ['Presented', 'Presentados', row.presented || 0],
                ['Issued', 'Emitidos', row.issued || 0]
            ]);
        });
        var agingRows = ((data.series || {}).aging || []).map(function (row) {
            return exportRow([
                ['Age range', 'Antigüedad', exportAgingLabel(row.id, row.label)],
                ['Merchandise lines', 'Renglones', row.rows_count || 0],
                ['Pieces in storage', 'Piezas en almacén', row.pieces_stored || 0]
            ]);
        });
        function rankingRows(rows, dimensionEnglish, dimensionSpanish) {
            return (rows || []).map(function (row) {
                return exportRow([
                    [dimensionEnglish, dimensionSpanish, exportDimensionLabel(row.label, dimensionEnglish === 'Client' ? 'client' : 'rig')],
                    ['Notices', 'Avisos', row.avisos || 0],
                    ['Merchandise lines', 'Renglones', row.merchandise_rows || 0],
                    ['Original pieces', 'Piezas originales', row.pieces_original || 0],
                    ['Exported pieces', 'Piezas exportadas', row.pieces_exported || 0],
                    ['Pieces in storage', 'Piezas en almacén', row.pieces_stored || 0]
                ]);
            });
        }
        var recentRows = (data.recent_avisos || []).map(function (row) {
            return exportRow([
                ['Notice', 'Aviso', row.notice_number || ''],
                ['Document code', 'Código de oficio', row.document_code || ''],
                ['Notice date', 'Fecha del aviso', row.metric_date || ''],
                ['Unloading date', 'Fecha de desembarque', row.landing_date || ''],
                ['Client', 'Cliente', row.client_name || ''],
                ['Rig / Project', 'Rig / Proyecto', row.rig_name || ''],
                ['Field', 'Campo', row.rig_field || ''],
                ['Status', 'Estado', exportStatusLabel(row.aviso_status, row.aviso_status || '')],
                ['Origin', 'Origen', exportOriginLabel(row.origin, row.origin || '')],
                ['PDF versions', 'Versiones PDF', row.version_count || 0],
                ['Latest version', 'Última versión', row.latest_version_no || ''],
                ['Latest PDF date', 'Fecha del último PDF', row.latest_version_at || '']
            ]);
        });

        appendJson(localized('Status', 'Estados'), statusRows, [24, 14]);
        appendJson(localized('Origin', 'Origen'), originRows, [24, 14]);
        appendJson(localized('Monthly', 'Mensual'), monthlyRows, [14, 12, 14, 14, 14, 12]);
        appendJson(localized('Aging', 'Antiguedad'), agingRows, [22, 18, 20]);
        appendJson(localized('Clients', 'Clientes'), rankingRows((data.rankings || {}).clients || [], 'Client', 'Cliente'), [36, 12, 18, 18, 18, 18]);
        appendJson('Rigs', rankingRows((data.rankings || {}).rigs || [], 'Rig / Project', 'Rig / Proyecto'), [36, 12, 18, 18, 18, 18]);
        appendJson(localized('Recent notices', 'Avisos recientes'), recentRows, [18, 20, 16, 18, 30, 28, 18, 16, 14, 14, 14, 20]);
        var filters = Object.keys(data.filters || {}).map(function (key) {
            return language === 'en'
                ? { filter: key, value: data.filters[key] === null ? '' : data.filters[key] }
                : { filtro: key, valor: data.filters[key] === null ? '' : data.filters[key] };
        });
        appendJson(localized('Filters', 'Filtros'), filters, [26, 48]);

        XLSX.writeFile(wb, safeFilename(localized('unloading-dashboard', 'dashboard-desembarques'), 'xlsx'), { compression: true });
    }

    function printReport() {
        var data = requirePayload();
        updatePrintMeta(data);
        window.print();
    }

    async function handleExport(type) {
        try {
            setButtons(false);
            if (type === 'pdf') await exportPdf();
            else if (type === 'excel') exportExcel();
            else if (type === 'print') printReport();
        } catch (error) {
            feedback(error && error.message ? error.message : (language === 'en' ? 'The export could not be generated.' : 'No fue posible generar la exportación.'), true);
        } finally {
            setButtons(integrityValid());
        }
    }

    exportButtons.forEach(function (button) {
        button.addEventListener('click', function () { handleExport(button.dataset.dashboardExport || ''); });
    });

    document.addEventListener('dashboard:data-ready', function (event) {
        var data = event.detail && event.detail.payload ? event.detail.payload : payload();
        updatePrintMeta(data || {});
        setButtons(Boolean(data && data.integrity && data.integrity.pass));
    });

    document.addEventListener('dashboard:runtime-ready', function () {
        setButtons(integrityValid());
    });

    window.addEventListener('beforeprint', function () {
        try { updatePrintMeta(requirePayload()); } catch (_) {}
    });

    setButtons(integrityValid());
}());
