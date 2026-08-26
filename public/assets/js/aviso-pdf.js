(function (window) {
    'use strict';

    function text(es, en) {
        return window.AppI18n && typeof window.AppI18n.text === 'function'
            ? window.AppI18n.text(es, en)
            : ((document.documentElement.lang || 'es').toLowerCase() === 'en' ? en : es);
    }

    var PAGE_WIDTH = 612;
    var PAGE_HEIGHT = 792;
    var TABLE_X = [54, 118, 217, 387, 558];
    var TABLE_BOTTOM = 702;
    var CONTINUATION_TOP = 127;
    var HEADER_TOP = 487;
    var HEADER_HEIGHT = 61.5;
    var ANNEX_X = [54, 118, 217, 558];
    var ANNEX_HEADER_TOP = 149;
    var ANNEX_HEADER_HEIGHT = 22;
    var ANNEX_CONTINUATION_TOP = 127;
    var ANNEX_BOTTOM = 700;
    var ANNEX_MIN_FINAL_BOTTOM = 492;

    function cleanText(value) {
        return String(value === null || typeof value === 'undefined' ? '' : value)
            .replace(/\u00a0/g, ' ')
            .replace(/[\u2012\u2013\u2014\u2212]/g, '-')
            .replace(/[\u2018\u2019]/g, "'")
            .replace(/[\u201c\u201d]/g, '"')
            .replace(/\u2026/g, '...')
            .replace(/[\t ]+/g, ' ')
            .trim();
    }

    function normalizeLabel(value) {
        var text = cleanText(value).toUpperCase();
        if (typeof text.normalize === 'function') {
            text = text.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }
        return text.replace(/[^A-Z0-9]+/g, ' ').trim();
    }

    function pad(value) {
        return String(value).padStart(2, '0');
    }

    function excelCellToParts(value) {
        if (value instanceof Date && !Number.isNaN(value.getTime())) {
            return {
                year: value.getFullYear(), month: value.getMonth() + 1, day: value.getDate(),
                hour: value.getHours(), minute: value.getMinutes(), second: value.getSeconds()
            };
        }

        if (typeof value === 'number' && window.XLSX && window.XLSX.SSF && typeof window.XLSX.SSF.parse_date_code === 'function') {
            var parsed = window.XLSX.SSF.parse_date_code(value);
            if (parsed) {
                return {
                    year: parsed.y, month: parsed.m, day: parsed.d,
                    hour: parsed.H || 0, minute: parsed.M || 0, second: Math.floor(parsed.S || 0)
                };
            }
        }

        var text = cleanText(value);
        var match = text.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/);
        if (match) {
            return {
                year: Number(match[1]), month: Number(match[2]), day: Number(match[3]),
                hour: Number(match[4] || 0), minute: Number(match[5] || 0), second: Number(match[6] || 0)
            };
        }

        return null;
    }

    function excelCellToIso(value) {
        var parts = excelCellToParts(value);
        if (!parts) {
            return null;
        }
        return parts.year + '-' + pad(parts.month) + '-' + pad(parts.day) + ' ' + pad(parts.hour) + ':' + pad(parts.minute) + ':' + pad(parts.second);
    }

    function formatSpanishDate(value, includeTime) {
        var parts = typeof value === 'object' && value && Object.prototype.hasOwnProperty.call(value, 'year')
            ? value
            : excelCellToParts(value);
        if (!parts) {
            return cleanText(value);
        }

        var months = ['ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO', 'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE'];
        var text = parts.day + ' DE ' + months[Math.max(0, Math.min(11, parts.month - 1))] + ' DE ' + parts.year;

        if (includeTime && (parts.hour || parts.minute)) {
            var hour = parts.hour;
            var suffix = hour >= 12 ? 'PM' : 'AM';
            var displayHour = hour % 12 || 12;
            text += ' / ETA APROXIMADO ' + displayHour + ':' + pad(parts.minute) + ' ' + suffix + '.';
        }

        return text;
    }

    function findValue(rows, labels) {
        var normalizedLabels = labels.map(normalizeLabel);
        for (var i = 0; i < rows.length; i += 1) {
            var label = normalizeLabel(rows[i] && rows[i][0]);
            if (!label) {
                continue;
            }
            for (var j = 0; j < normalizedLabels.length; j += 1) {
                if (label.indexOf(normalizedLabels[j]) === 0) {
                    return rows[i][1];
                }
            }
        }
        return null;
    }

    function bufferToHex(buffer) {
        return Array.from(new Uint8Array(buffer)).map(function (byte) {
            return byte.toString(16).padStart(2, '0');
        }).join('');
    }

    async function parseExcel(file) {
        if (!window.XLSX || typeof window.XLSX.read !== 'function') {
            throw new Error(text('La librería XLSX no está disponible.', 'The XLSX library is not available.'));
        }
        if (!file || typeof file.arrayBuffer !== 'function') {
            throw new Error(text('Selecciona un archivo de Excel válido.', 'Select a valid Excel file.'));
        }

        var arrayBuffer = await file.arrayBuffer();
        var workbook = window.XLSX.read(arrayBuffer, { type: 'array', cellDates: true, raw: true });
        var sheetName = workbook.SheetNames && workbook.SheetNames.length ? workbook.SheetNames[0] : '';
        if (!sheetName || !workbook.Sheets[sheetName]) {
            throw new Error(text('El Excel no contiene una hoja legible.', 'The Excel file does not contain a readable worksheet.'));
        }

        var rows = window.XLSX.utils.sheet_to_json(workbook.Sheets[sheetName], {
            header: 1,
            raw: true,
            defval: null,
            blankrows: true
        });

        var details = {
            manifiesto: cleanText(findValue(rows, ['MANIFIESTO'])),
            medio_transporte: cleanText(findValue(rows, ['MEDIO DE TRANSPORTE'])),
            imo_transporte: cleanText(findValue(rows, ['IMO'])),
            consignataria: cleanText(findValue(rows, ['CONSIGNATARIA'])),
            fecha_embarque: excelCellToIso(findValue(rows, ['FECHA DE EMBARQUE'])),
            lugar_desembarque: cleanText(findValue(rows, ['LUGAR DE DESEMBAQUE', 'LUGAR DE DESEMBARQUE'])),
            fecha_desembarque_eta: excelCellToIso(findValue(rows, ['FECHA DE DESEMBARQUE'])),
            domicilio_almacenamiento: cleanText(findValue(rows, ['DOMICILIO AL QUE SE TRASLADARA PARA SU ALMACENAMIENTO'])),
            domicilio_reparacion: cleanText(findValue(rows, ['DOMICILIO AL QUE SE TRASLADARA PARA SU REPARACION Y MANTENIMIENTO'])) || 'N/A',
            source_excel_name: cleanText(file.name)
        };

        var headerIndex = -1;
        for (var rowIndex = 0; rowIndex < rows.length; rowIndex += 1) {
            var row = rows[rowIndex] || [];
            var descriptionHeader = normalizeLabel(row[2]);
            var pedimentoHeader = normalizeLabel(row[12]);
            if (descriptionHeader.indexOf('MERCANCIAS') !== -1 && pedimentoHeader.indexOf('PEDIMENTO') !== -1) {
                headerIndex = rowIndex;
                break;
            }
        }

        if (headerIndex < 0) {
            throw new Error(text('No se encontró la cabecera de mercancías del Excel.', 'The merchandise header was not found in the Excel file.'));
        }

        var items = [];
        for (var itemIndex = headerIndex + 1; itemIndex < rows.length; itemIndex += 1) {
            var itemRow = rows[itemIndex] || [];
            var description = cleanText(itemRow[2]);
            var pedimento = cleanText(itemRow[12]);
            var serial = cleanText(itemRow[9]);
            var brand = cleanText(itemRow[10]);
            var quantityRaw = itemRow[14];

            if (!description && !pedimento && !serial && !brand && (quantityRaw === null || typeof quantityRaw === 'undefined' || quantityRaw === '')) {
                continue;
            }
            if (!description) {
                continue;
            }

            var quantity = null;
            if (quantityRaw !== null && typeof quantityRaw !== 'undefined' && quantityRaw !== '' && !Number.isNaN(Number(quantityRaw))) {
                quantity = Number(quantityRaw);
            }

            items.push({
                source_row: itemIndex + 1,
                item_no: cleanText(itemRow[0]),
                unidad: cleanText(itemRow[1]),
                descripcion: description,
                serial_number: serial,
                marca: brand,
                clave: cleanText(itemRow[11]).toUpperCase(),
                num_pedimento: pedimento,
                partida: cleanText(itemRow[13]),
                cantidad: quantity,
                importer_name: ''
            });
        }

        if (!items.length) {
            throw new Error(text('El Excel no contiene mercancías para el aviso.', 'The Excel file does not contain merchandise for the notice.'));
        }

        if (window.crypto && window.crypto.subtle && typeof window.crypto.subtle.digest === 'function') {
            try {
                details.source_excel_sha256 = bufferToHex(await window.crypto.subtle.digest('SHA-256', arrayBuffer));
            } catch (hashError) {
                details.source_excel_sha256 = '';
            }
        } else {
            details.source_excel_sha256 = '';
        }

        return {
            sheetName: sheetName,
            details: details,
            items: items,
            rawRows: rows
        };
    }

    function normalizePedimento(value) {
        return cleanText(value).toLowerCase().replace(/[^0-9a-z]+/g, '');
    }

    function normalizeKey(value) {
        return cleanText(value).toUpperCase().replace(/\s+/g, '');
    }

    function itemPedimentos(item) {
        var relations = Array.isArray(item && item.pedimentos) ? item.pedimentos.filter(Boolean) : [];
        if (relations.length) {
            return relations.map(function (relation) {
                return {
                    clave: normalizeKey(relation.key || relation.clave || ''),
                    num_pedimento: cleanText(relation.number || relation.num_pedimento || relation.pedimento || ''),
                    importer_name: cleanText(relation.importer_name || item.importer_name || '')
                };
            }).filter(function (relation) { return relation.num_pedimento; });
        }
        var legacyNumber = cleanText(item && item.num_pedimento || '');
        return legacyNumber ? [{
            clave: normalizeKey(item && item.clave || ''),
            num_pedimento: legacyNumber,
            importer_name: cleanText(item && item.importer_name || '')
        }] : [];
    }

    function matchImporters(items, pedimentoHeaders) {
        var headers = Array.isArray(pedimentoHeaders) ? pedimentoHeaders : [];
        var map = {};
        headers.forEach(function (header) {
            if (!header || typeof header !== 'object') {
                return;
            }
            var numberKey = normalizePedimento(header.num_pedimento || '');
            var claveKey = normalizeKey(header.cve_pedimento || '');
            var importer = cleanText(header.razon_social || '');
            if (!numberKey || !importer) {
                return;
            }
            map[claveKey + '|' + numberKey] = importer;
            if (!map['|' + numberKey]) {
                map['|' + numberKey] = importer;
            }
        });

        return (Array.isArray(items) ? items : []).map(function (item) {
            var copy = Object.assign({}, item);
            var relations = itemPedimentos(copy).map(function (relation) {
                var numberKey = normalizePedimento(relation.num_pedimento || '');
                var claveKey = normalizeKey(relation.clave || '');
                if (!cleanText(relation.importer_name) && numberKey) {
                    relation.importer_name = map[claveKey + '|' + numberKey] || map['|' + numberKey] || '';
                }
                return relation;
            });
            copy.pedimentos = relations.map(function (relation) {
                return { key: relation.clave, number: relation.num_pedimento, importer_name: relation.importer_name };
            });
            var numberKey = normalizePedimento(copy.num_pedimento || '');
            var claveKey = normalizeKey(copy.clave || '');
            if (!cleanText(copy.importer_name)) {
                if (numberKey) {
                    copy.importer_name = map[claveKey + '|' + numberKey] || map['|' + numberKey] || '';
                } else if (relations.length) {
                    copy.importer_name = cleanText(relations[0].importer_name || '');
                }
            }
            return copy;
        });
    }

    function groupItems(items) {
        var groups = [];
        var map = {};
        (Array.isArray(items) ? items : []).forEach(function (item) {
            var relations = itemPedimentos(item);
            var signature = relations.map(function (relation) {
                return normalizeKey(relation.clave || '') + ':' + normalizePedimento(relation.num_pedimento || '');
            }).sort().join(',');
            var importer = cleanText(item.importer_name || (relations[0] && relations[0].importer_name) || '');
            var key = signature + '|' + importer.toLowerCase();
            if (!map[key]) {
                map[key] = {
                    clave: relations.length === 1 ? relations[0].clave : '',
                    num_pedimento: relations.length === 1 ? relations[0].num_pedimento : '',
                    pedimentos: relations,
                    importer_name: importer,
                    items: []
                };
                groups.push(map[key]);
            }
            map[key].items.push(item);
        });
        return groups;
    }

    function wrapText(text, font, size, maxWidth) {
        var paragraphs = String(text || '').replace(/\r/g, '').split('\n');
        var lines = [];

        paragraphs.forEach(function (paragraph) {
            var cleaned = cleanText(paragraph);
            if (!cleaned) {
                lines.push('');
                return;
            }

            var words = cleaned.split(/\s+/);
            var current = '';
            words.forEach(function (word) {
                var candidate = current ? current + ' ' + word : word;
                if (!current || font.widthOfTextAtSize(candidate, size) <= maxWidth) {
                    current = candidate;
                    return;
                }
                lines.push(current);
                current = word;

                if (font.widthOfTextAtSize(current, size) > maxWidth) {
                    var fragment = '';
                    for (var c = 0; c < current.length; c += 1) {
                        var next = fragment + current[c];
                        if (fragment && font.widthOfTextAtSize(next, size) > maxWidth) {
                            lines.push(fragment);
                            fragment = current[c];
                        } else {
                            fragment = next;
                        }
                    }
                    current = fragment;
                }
            });
            if (current) {
                lines.push(current);
            }
        });

        while (lines.length && lines[lines.length - 1] === '') {
            lines.pop();
        }
        return lines;
    }

    function drawLines(page, lines, x, top, width, font, size, lineHeight, color, align) {
        var textColor = color || window.PDFLib.rgb(0, 0, 0);
        var alignment = align || 'left';
        lines.forEach(function (line, index) {
            if (line === '') {
                return;
            }
            var textWidth = font.widthOfTextAtSize(line, size);
            var drawX = x;
            if (alignment === 'right') {
                drawX = x + width - textWidth;
            } else if (alignment === 'center') {
                drawX = x + (width - textWidth) / 2;
            }
            page.drawText(line, {
                x: drawX,
                y: PAGE_HEIGHT - top - size - index * lineHeight,
                size: size,
                font: font,
                color: textColor
            });
        });
        return lines.length * lineHeight;
    }

    function drawWrapped(page, text, x, top, width, font, size, lineHeight, options) {
        var opts = options || {};
        var lines = wrapText(text, font, size, width);
        drawLines(page, lines, x, top, width, font, size, lineHeight, opts.color, opts.align);
        return { lines: lines, height: lines.length * lineHeight };
    }

    function drawRectTop(page, x, top, width, height, options) {
        var opts = options || {};
        page.drawRectangle({
            x: x,
            y: PAGE_HEIGHT - top - height,
            width: width,
            height: height,
            color: opts.color,
            borderColor: opts.borderColor,
            borderWidth: opts.borderWidth
        });
    }

    function drawLineTop(page, x1, y1Top, x2, y2Top, thickness) {
        page.drawLine({
            start: { x: x1, y: PAGE_HEIGHT - y1Top },
            end: { x: x2, y: PAGE_HEIGHT - y2Top },
            thickness: thickness || 0.45,
            color: window.PDFLib.rgb(0, 0, 0)
        });
    }

    function fitTextToBox(text, font, maxSize, minSize, width, maxHeight) {
        for (var size = maxSize; size >= minSize; size -= 0.25) {
            var lineHeight = size * 1.22;
            var lines = wrapText(text, font, size, width);
            if (lines.length * lineHeight <= maxHeight) {
                return { size: size, lineHeight: lineHeight, lines: lines };
            }
        }
        var fallbackSize = minSize;
        return {
            size: fallbackSize,
            lineHeight: fallbackSize * 1.18,
            lines: wrapText(text, font, fallbackSize, width)
        };
    }

    function formatQuantity(item) {
        var quantity = item.cantidad;
        var numeric = quantity === null || typeof quantity === 'undefined' || quantity === '' ? null : Number(quantity);
        var quantityText = numeric !== null && !Number.isNaN(numeric)
            ? (Number.isInteger(numeric) ? String(numeric) : String(numeric).replace(/\.0+$/, ''))
            : 'N/A';
        var unit = cleanText(item.unidad || '').toUpperCase();
        if (!unit || unit === 'PC' || unit === 'PCS' || unit === 'PZA' || unit === 'PZAS') {
            unit = numeric === 1 ? 'PIEZA' : 'PIEZAS';
        }
        return quantityText + (unit ? ' ' + unit : '');
    }

    function buildMerchandiseText(group, aviso) {
        var details = aviso.details || {};
        var document = aviso.document || {};
        var rigName = cleanText(document.rig_name || '');
        var rigArea = cleanText(document.rig_area || '') || 'CUBIERTA';
        var blocks = [];

        group.items.forEach(function (item) {
            var lines = ['DESCRIPCION', cleanText(item.descripcion), 'CANTIDAD: ' + formatQuantity(item), 'DATOS DE IDENTIFICACION:', cleanText(item.serial_number) || 'N/A'];
            if (cleanText(item.marca)) {
                lines.push('MARCA: ' + cleanText(item.marca));
            }
            if (rigName) {
                lines.push('AREA PERTENECIENTE EN EL RIG ' + rigName.toUpperCase() + ':');
                lines.push(rigArea.toUpperCase());
            }
            var relations = itemPedimentos(item);
            if (relations.length) {
                relations.forEach(function (relation) {
                    var pedimentoLine = 'PEDIMENTO DE IMPORTACION';
                    if (relation.clave) {
                        pedimentoLine += ' CLAVE ' + relation.clave;
                    }
                    pedimentoLine += ': ' + relation.num_pedimento;
                    lines.push(pedimentoLine);
                });
            } else {
                lines.push('PEDIMENTO DE IMPORTACION: N/A');
            }
            var partida = cleanText(item.partida || '');
            if (partida && partida !== '0') {
                lines.push('PARTIDA:' + partida);
            }
            blocks.push(lines.join('\n'));
        });

        return blocks.join('\n\n');
    }

    function buildLandingText(details, isFirstGroup, includeDisincorporated) {
        var lines = [];
        if (isFirstGroup) {
            lines.push('DESEMBARQUE');
            lines.push('NUMERO Y FECHA DE MANIFIESTO DE EMBARQUE: ' + (cleanText(details.manifiesto) || 'N/A') + '/');
            var transport = 'MEDIO DE TRANSPORTE: ' + (cleanText(details.medio_transporte) || 'N/A');
            if (cleanText(details.imo_transporte)) {
                transport += '. / IMO ' + cleanText(details.imo_transporte);
            }
            if (cleanText(details.consignataria)) {
                transport += ' / CONSIGNATARIA ' + cleanText(details.consignataria);
            }
            lines.push(transport);
            lines.push('FECHA DE EMBARQUE: ' + (formatSpanishDate(details.fecha_embarque, false) || 'N/A'));
            lines.push('LUGAR DE DESEMBARQUE: ' + (cleanText(details.lugar_desembarque) || 'N/A'));
            lines.push('FECHA DE DESEMBARQUE: ' + (formatSpanishDate(details.fecha_desembarque_eta, true) || 'N/A'));
        }
        lines.push('DOMICILIO AL QUE SE TRASLADARA PARA SU ALMACENAMIENTO: ' + (cleanText(details.domicilio_almacenamiento) || 'N/A'));
        lines.push('DOMICILIO AL QUE SE TRASLADARA PARA SU REPARACION Y MANTENIMIENTO: ' + (cleanText(details.domicilio_reparacion) || 'N/A'));
        if (includeDisincorporated) {
            lines.push('');
            lines.push('ESTA MERCANCIA FUE DESINCORPORADA DEL RIG INDICADO EN EL AVISO');
        }
        return lines.join('\n');
    }

    function buildDocumentation(details, groups) {
        var lines = ['Por lo anterior, se anexaron al presente la siguiente documentación:'];
        if (cleanText(details.manifiesto)) {
            lines.push('MANIFIESTO DE EMBARQUE NUMERO: ' + cleanText(details.manifiesto));
        }
        var seen = {};
        groups.forEach(function (group) {
            (Array.isArray(group.items) ? group.items : []).forEach(function (item) {
                itemPedimentos(item).forEach(function (relation) {
                    var number = cleanText(relation.num_pedimento || '');
                    var key = normalizeKey(relation.clave || '') + '|' + normalizePedimento(number);
                    if (!number || seen[key]) {
                        return;
                    }
                    seen[key] = true;
                    var prefix = 'PEDIMENTO DE IMPORTACION TEMPORAL';
                    if (relation.clave) {
                        prefix += ' CLAVE ' + relation.clave;
                    }
                    lines.push(prefix + ' NUMERO: ' + number);
                });
            });
        });
        return lines.join('\n');
    }

    function createTemplatePage(outputPdf, embeddedTemplate) {
        var page = outputPdf.addPage([PAGE_WIDTH, PAGE_HEIGHT]);
        page.drawPage(embeddedTemplate, { x: 0, y: 0, width: PAGE_WIDTH, height: PAGE_HEIGHT });
        return page;
    }

    function drawTableHeader(page, boldFont, topOverride) {
        var headerTop = Number.isFinite(Number(topOverride)) ? Number(topOverride) : HEADER_TOP;
        var gray = window.PDFLib.rgb(0.75, 0.75, 0.75);
        for (var i = 0; i < 4; i += 1) {
            drawRectTop(page, TABLE_X[i], headerTop, TABLE_X[i + 1] - TABLE_X[i], HEADER_HEIGHT, {
                color: gray,
                borderColor: window.PDFLib.rgb(0, 0, 0),
                borderWidth: 0.45
            });
        }

        drawWrapped(page, 'MANIFIESTO', TABLE_X[0] + 4, headerTop + 24, TABLE_X[1] - TABLE_X[0] - 8, boldFont, 9.7, 11, { align: 'center' });
        drawWrapped(page, 'DATOS DEL\nIMPORTADOR\nNOMBRE,\nDENOMINACION O\nRAZON SOCIAL', TABLE_X[1] + 5, headerTop + 3, TABLE_X[2] - TABLE_X[1] - 10, boldFont, 9.4, 11.5, { align: 'center' });
        drawWrapped(page, 'MERCANCIAS', TABLE_X[2] + 5, headerTop + 19, TABLE_X[3] - TABLE_X[2] - 10, boldFont, 9.7, 11, { align: 'center' });
        drawWrapped(page, 'DESEMBARQUE', TABLE_X[3] + 5, headerTop + 19, TABLE_X[4] - TABLE_X[3] - 10, boldFont, 9.7, 11, { align: 'center' });
        return headerTop + HEADER_HEIGHT;
    }

    function drawTableSegment(page, top, height, segment, fonts) {
        var x;
        drawLineTop(page, TABLE_X[0], top, TABLE_X[4], top, 0.45);
        drawLineTop(page, TABLE_X[0], top + height, TABLE_X[4], top + height, 0.45);
        for (var i = 0; i < TABLE_X.length; i += 1) {
            x = TABLE_X[i];
            drawLineTop(page, x, top, x, top + height, 0.45);
        }

        var padX = 5;
        var padTop = 4;
        var lineHeight = 7.0;
        drawLines(page, segment.manifestLines, TABLE_X[0] + padX, top + padTop, TABLE_X[1] - TABLE_X[0] - padX * 2, fonts.body7, 6.7, lineHeight);
        drawLines(page, segment.importerLines, TABLE_X[1] + padX, top + padTop, TABLE_X[2] - TABLE_X[1] - padX * 2, fonts.body7, 6.7, lineHeight);
        drawLines(page, segment.merchLines, TABLE_X[2] + padX, top + padTop, TABLE_X[3] - TABLE_X[2] - padX * 2, fonts.body6, 6.0, lineHeight);
        drawLines(page, segment.landingLines, TABLE_X[3] + padX, top + padTop, TABLE_X[4] - TABLE_X[3] - padX * 2, fonts.italic6, 6.0, lineHeight);
    }

    function getAnnexPhotoItem(photo, items) {
        var sourceRow = Number(photo && photo.item_source_row || 0);
        var itemId = Number(photo && photo.aviso_item_id || 0);
        var sourceItems = Array.isArray(items) ? items : [];
        for (var index = 0; index < sourceItems.length; index += 1) {
            if (sourceRow && Number(sourceItems[index].source_row || 0) === sourceRow) {
                return sourceItems[index];
            }
            if (!sourceRow && itemId && Number(sourceItems[index].id || 0) === itemId) {
                return sourceItems[index];
            }
        }
        return null;
    }

    function getAnnexPhotoCaption(photo, items) {
        var caption = cleanText(photo && photo.caption || '');
        if (caption) {
            return caption;
        }
        var item = getAnnexPhotoItem(photo, items);
        if (item && cleanText(item.descripcion)) {
            return cleanText(item.descripcion);
        }
        var filename = cleanText(photo && photo.original_name || '');
        if (filename) {
            return filename.replace(/\.[a-z0-9]{2,5}$/i, '');
        }
        return 'FOTOGRAFIA DEL DESEMBARQUE';
    }

    function buildAnnexMerchandiseText(pagePhotos, items) {
        var seen = {};
        var blocks = [];
        (Array.isArray(pagePhotos) ? pagePhotos : []).forEach(function (photo) {
            var item = getAnnexPhotoItem(photo, items);
            if (!item) {
                return;
            }
            var key = Number(item.id || 0) || ('row:' + Number(item.source_row || 0));
            if (!key || seen[key]) {
                return;
            }
            seen[key] = true;
            var lines = [
                'DESCRIPCION',
                cleanText(item.descripcion),
                'CANTIDAD: ' + formatQuantity(item),
                'DATOS DE IDENTIFICACION:',
                cleanText(item.serial_number) || 'N/A'
            ];
            blocks.push(lines.join('\n'));
        });
        return blocks.join('\n\n');
    }

    function drawAnnexHeader(page, boldFont) {
        var gray = window.PDFLib.rgb(0.75, 0.75, 0.75);
        for (var index = 0; index < 3; index += 1) {
            drawRectTop(page, ANNEX_X[index], ANNEX_HEADER_TOP, ANNEX_X[index + 1] - ANNEX_X[index], ANNEX_HEADER_HEIGHT, {
                color: gray,
                borderColor: window.PDFLib.rgb(0, 0, 0),
                borderWidth: 0.45
            });
        }
        drawWrapped(page, 'MANIFIESTO', ANNEX_X[0] + 3, ANNEX_HEADER_TOP + 6, ANNEX_X[1] - ANNEX_X[0] - 6, boldFont, 8.8, 10, { align: 'center' });
        drawWrapped(page, 'MERCANCIAS', ANNEX_X[1] + 3, ANNEX_HEADER_TOP + 6, ANNEX_X[2] - ANNEX_X[1] - 6, boldFont, 8.8, 10, { align: 'center' });
        drawWrapped(page, 'IMAGENES', ANNEX_X[2] + 3, ANNEX_HEADER_TOP + 6, ANNEX_X[3] - ANNEX_X[2] - 6, boldFont, 8.8, 10, { align: 'center' });
        return ANNEX_HEADER_TOP + ANNEX_HEADER_HEIGHT;
    }

    function drawAnnexFrame(page, top, bottom) {
        drawLineTop(page, ANNEX_X[0], top, ANNEX_X[3], top, 0.45);
        drawLineTop(page, ANNEX_X[0], bottom, ANNEX_X[3], bottom, 0.45);
        for (var index = 0; index < ANNEX_X.length; index += 1) {
            drawLineTop(page, ANNEX_X[index], top, ANNEX_X[index], bottom, 0.45);
        }
    }

    function drawEmbeddedPhoto(page, asset, x, top, width, height) {
        var image = asset.image;
        var naturalWidth = Number(asset.width || image.width || 1);
        var naturalHeight = Number(asset.height || image.height || 1);
        var scale = Math.min(width / naturalWidth, height / naturalHeight);
        var drawWidth = Math.max(1, naturalWidth * scale);
        var drawHeight = Math.max(1, naturalHeight * scale);
        var drawX = x + (width - drawWidth) / 2;
        var drawTop = top + (height - drawHeight) / 2;
        page.drawImage(image, {
            x: drawX,
            y: PAGE_HEIGHT - drawTop - drawHeight,
            width: drawWidth,
            height: drawHeight
        });
    }

    function blobToJpegBytes(blob) {
        function drawToCanvas(source, width, height) {
            var maxDimension = 1800;
            var scale = Math.min(1, maxDimension / Math.max(width, height));
            var targetWidth = Math.max(1, Math.round(width * scale));
            var targetHeight = Math.max(1, Math.round(height * scale));
            var canvas = document.createElement('canvas');
            canvas.width = targetWidth;
            canvas.height = targetHeight;
            var context = canvas.getContext('2d', { alpha: false });
            if (!context) {
                throw new Error(text('No fue posible preparar una fotografía para el PDF.', 'A photo could not be prepared for the PDF.'));
            }
            context.fillStyle = '#ffffff';
            context.fillRect(0, 0, targetWidth, targetHeight);
            context.drawImage(source, 0, 0, targetWidth, targetHeight);
            return new Promise(function (resolve, reject) {
                canvas.toBlob(function (jpegBlob) {
                    if (!jpegBlob) {
                        reject(new Error(text('No fue posible convertir una fotografía para el PDF.', 'A photo could not be converted for the PDF.')));
                        return;
                    }
                    jpegBlob.arrayBuffer().then(function (buffer) {
                        resolve({ bytes: buffer, width: targetWidth, height: targetHeight });
                    }).catch(reject);
                }, 'image/jpeg', 0.86);
            });
        }

        if (typeof window.createImageBitmap === 'function') {
            return window.createImageBitmap(blob, { imageOrientation: 'from-image' })
                .catch(function () { return window.createImageBitmap(blob); })
                .then(function (bitmap) {
                    return drawToCanvas(bitmap, bitmap.width, bitmap.height).finally(function () {
                        if (bitmap && typeof bitmap.close === 'function') {
                            bitmap.close();
                        }
                    });
                });
        }

        return new Promise(function (resolve, reject) {
            var url = URL.createObjectURL(blob);
            var image = new Image();
            image.onload = function () {
                try {
                    drawToCanvas(image, image.naturalWidth || image.width, image.naturalHeight || image.height)
                        .then(resolve)
                        .catch(reject)
                        .finally(function () { URL.revokeObjectURL(url); });
                } catch (error) {
                    URL.revokeObjectURL(url);
                    reject(error);
                }
            };
            image.onerror = function () {
                URL.revokeObjectURL(url);
                reject(new Error(text('No fue posible leer una fotografía del anexo.', 'A photo in the annex could not be read.')));
            };
            image.src = url;
        });
    }

    async function prepareAnnexPhoto(outputPdf, photo, items) {
        var url = cleanText(photo.preview_url || photo.download_url || '');
        if (!url) {
            throw new Error(text('Una fotografía del anexo no tiene una URL válida.', 'A photo in the annex does not have a valid URL.'));
        }
        var response = await fetch(url, { cache: 'no-store', credentials: 'same-origin' });
        if (!response.ok) {
            throw new Error(text('No fue posible cargar la fotografía "', 'The photo "') + (cleanText(photo.original_name) || String(photo.file_id || '')) + text('".', '" could not be loaded.'));
        }
        var blob = await response.blob();
        if (String(blob.type || '').toLowerCase().indexOf('image/') !== 0) {
            throw new Error(text('El archivo "', 'The file "') + (cleanText(photo.original_name) || String(photo.file_id || '')) + text('" no es una imagen válida.', '" is not a valid image.'));
        }
        var converted = await blobToJpegBytes(blob);
        var embedded = await outputPdf.embedJpg(converted.bytes);
        return {
            photo: photo,
            image: embedded,
            width: converted.width,
            height: converted.height,
            caption: getAnnexPhotoCaption(photo, items)
        };
    }

    function drawAnnexPhotoSlot(page, asset, x, top, width, imageHeight, captionHeight, font, boldFont) {
        var caption = cleanText(asset.caption || '');
        var captionFit = fitTextToBox(caption, font, 6.8, 5.4, width, captionHeight);
        drawLines(page, captionFit.lines, x, top, width, font, captionFit.size, captionFit.lineHeight, null, 'center');
        var imageTop = top + captionHeight;
        drawEmbeddedPhoto(page, asset, x, imageTop, width, imageHeight);
        return captionHeight + imageHeight;
    }

    async function renderPhotoAnnex(outputPdf, embeddedTemplate, fonts, details, items, photos) {
        var sourcePhotos = Array.isArray(photos) ? photos.filter(function (photo) {
            return photo && Number(photo.file_id || 0) > 0;
        }) : [];
        if (!sourcePhotos.length) {
            return null;
        }

        var assets = [];
        for (var photoIndex = 0; photoIndex < sourcePhotos.length; photoIndex += 1) {
            assets.push(await prepareAnnexPhoto(outputPdf, sourcePhotos[photoIndex], items));
        }

        var assetIndex = 0;
        var pageIndex = 0;
        var lastPage = null;
        var lastBottom = ANNEX_MIN_FINAL_BOTTOM;

        while (assetIndex < assets.length) {
            var page = createTemplatePage(outputPdf, embeddedTemplate);
            var firstPage = pageIndex === 0;
            var contentTop = firstPage ? drawAnnexHeader(page, fonts.bold) : ANNEX_CONTINUATION_TOP;
            var imageX = ANNEX_X[2] + 6;
            var imageWidth = ANNEX_X[3] - ANNEX_X[2] - 12;
            var gap = 10;
            var cellWidth = (imageWidth - gap) / 2;
            var pageAssets = [];
            var usedBottom = contentTop + 8;

            if (firstPage && assetIndex < assets.length) {
                var firstAsset = assets[assetIndex];
                var firstRatio = Number(firstAsset.width || 1) / Math.max(1, Number(firstAsset.height || 1));
                if (firstRatio >= 1.15) {
                    var wideTop = contentTop + 8;
                    var wideCaption = 18;
                    var wideImageHeight = 145;
                    drawAnnexPhotoSlot(page, firstAsset, imageX, wideTop, imageWidth, wideImageHeight, wideCaption, fonts.body6, fonts.bold);
                    usedBottom = wideTop + wideCaption + wideImageHeight + 12;
                    pageAssets.push(firstAsset.photo);
                    assetIndex += 1;
                }
            }

            var gridTop = usedBottom;
            var rowHeight = 142;
            var maxRows = Math.max(1, Math.floor((ANNEX_BOTTOM - gridTop) / rowHeight));
            var maxGridPhotos = maxRows * 2;
            var gridCount = Math.min(maxGridPhotos, assets.length - assetIndex);
            for (var gridIndex = 0; gridIndex < gridCount; gridIndex += 1) {
                var asset = assets[assetIndex + gridIndex];
                var row = Math.floor(gridIndex / 2);
                var column = gridIndex % 2;
                var slotTop = gridTop + row * rowHeight;
                var slotX = imageX + column * (cellWidth + gap);
                drawAnnexPhotoSlot(page, asset, slotX, slotTop, cellWidth, 112, 22, fonts.body6, fonts.bold);
                pageAssets.push(asset.photo);
                usedBottom = Math.max(usedBottom, slotTop + 134);
            }
            assetIndex += gridCount;

            // Evita un bucle si una fotografía excepcional no encontró espacio después del bloque ancho.
            if (!pageAssets.length && assetIndex < assets.length) {
                var forced = assets[assetIndex];
                drawAnnexPhotoSlot(page, forced, imageX, contentTop + 8, imageWidth, 145, 18, fonts.body6, fonts.bold);
                pageAssets.push(forced.photo);
                usedBottom = contentTop + 175;
                assetIndex += 1;
            }

            var finalPage = assetIndex >= assets.length;
            var frameBottom = Math.min(ANNEX_BOTTOM, Math.max(usedBottom + 8, finalPage ? ANNEX_MIN_FINAL_BOTTOM : usedBottom + 8));
            drawAnnexFrame(page, contentTop, frameBottom);

            if (firstPage) {
                drawWrapped(page, cleanText(details.manifiesto) || 'N/A', ANNEX_X[0] + 5, contentTop + 8, ANNEX_X[1] - ANNEX_X[0] - 10, fonts.body7, 7.0, 8.5, { align: 'center' });
            }

            var merchandiseText = buildAnnexMerchandiseText(pageAssets, items);
            if (merchandiseText) {
                var merchFit = fitTextToBox(
                    merchandiseText,
                    fonts.body6,
                    6.5,
                    5.0,
                    ANNEX_X[2] - ANNEX_X[1] - 10,
                    Math.max(40, frameBottom - contentTop - 12)
                );
                drawLines(page, merchFit.lines, ANNEX_X[1] + 5, contentTop + 7, ANNEX_X[2] - ANNEX_X[1] - 10, fonts.body6, merchFit.size, merchFit.lineHeight);
            }

            lastPage = page;
            lastBottom = frameBottom;
            pageIndex += 1;
        }

        return {
            page: lastPage,
            top: Math.max(lastBottom + 18, ANNEX_MIN_FINAL_BOTTOM + 13),
            count: assets.length
        };
    }

    function expandDocumentTokens(text, values) {
        var source = String(text || '');
        var map = values || {};
        return source
            .replace(/\{\{NOMBRE_RIG\}\}/gi, cleanText(map.rigName) || 'N/A')
            .replace(/\{\{IMO_RIG\}\}/gi, cleanText(map.rigImo) || 'N/A')
            .replace(/\{\{CAMPO_RIG\}\}/gi, cleanText(map.rigField) || 'N/A')
            .replace(/\{\{COMITENTE\}\}/gi, cleanText(map.comitente) || 'N/A')
            .replace(/\{\{MANIFIESTO\}\}/gi, cleanText(map.manifiesto) || 'N/A');
    }

    async function generate(options) {
        if (!window.PDFLib || !window.PDFLib.PDFDocument) {
            throw new Error(text('La librería PDF-lib no está disponible.', 'The PDF-lib library is not available.'));
        }

        var opts = options || {};
        var templateUrl = cleanText(opts.templateUrl);
        var formData = opts.formData || {};
        var aviso = opts.aviso || {};
        var details = aviso.details || {};
        var items = Array.isArray(aviso.items) ? aviso.items : [];
        var photos = Array.isArray(aviso.photos) ? aviso.photos : [];

        if (!templateUrl) {
            throw new Error(text('No se configuró la plantilla del aviso.', 'The notice template was not configured.'));
        }
        if (!items.length) {
            throw new Error(text('Importa el Excel antes de generar el aviso.', 'Import the Excel file before generating the notice.'));
        }

        var response = await fetch(templateUrl, { cache: 'no-store', credentials: 'same-origin' });
        if (!response.ok) {
            throw new Error(text('No fue posible cargar la plantilla PDF.', 'The PDF template could not be loaded.'));
        }

        var templateBytes = await response.arrayBuffer();
        var templatePdf = await window.PDFLib.PDFDocument.load(templateBytes);
        if (!templatePdf.getPageCount()) {
            throw new Error(text('La plantilla PDF no contiene páginas.', 'The PDF template does not contain any pages.'));
        }

        var outputPdf = await window.PDFLib.PDFDocument.create();
        var templatePage = templatePdf.getPage(0);
        var embeddedTemplate = await outputPdf.embedPage(templatePage);
        var font = await outputPdf.embedFont(window.PDFLib.StandardFonts.Helvetica);
        var boldFont = await outputPdf.embedFont(window.PDFLib.StandardFonts.HelveticaBold);
        var italicFont = await outputPdf.embedFont(window.PDFLib.StandardFonts.HelveticaOblique);
        var fonts = { body6: font, body7: font, bold: boldFont, italic6: italicFont };

        var page = createTemplatePage(outputPdf, embeddedTemplate);
        var code = cleanText(formData.documentCode || details.document_code || '');
        var noticeNumber = cleanText(formData.noticeNumber || details.notice_number || details.manifiesto || '');
        if (!code && noticeNumber) {
            code = 'MADE-' + noticeNumber;
        }
        if (code) {
            var codeWidth = boldFont.widthOfTextAtSize(code, 9.6);
            page.drawText(code, { x: 558 - codeWidth, y: PAGE_HEIGHT - 129 - 9.6, size: 9.6, font: boldFont, color: window.PDFLib.rgb(0, 0, 0) });
        }

        var recipientText = cleanText(formData.recipient || details.recipient_text || '');
        drawWrapped(page, recipientText, 54, 143, 360, font, 9.6, 14, {});
        drawWrapped(page, 'PRESENTE', 54, 171, 200, font, 9.6, 12, {});

        var rigName = cleanText(formData.rigName || details.rig_name || '');
        var rigImo = cleanText(formData.rigImo || details.rig_imo || '');
        var rigField = cleanText(formData.rigField || details.rig_field || '');
        var tokenValues = {
            rigName: rigName,
            rigImo: rigImo,
            rigField: rigField,
            comitente: formData.comitente || details.comitente || '',
            manifiesto: details.manifiesto || noticeNumber || ''
        };
        var documentTitle = cleanText(expandDocumentTokens(formData.documentTitle || details.document_title || '', tokenValues));
        if (!documentTitle) {
            documentTitle = 'AVISO DE DESEMBARQUE, DE CONFORMIDAD CON LOS PARRAFOS CUARTO Y QUINTO DE LA REGLA 4.2.11 DE LAS REGLAS GENERALES DE COMERCIO EXTERIOR. DE MERCANCIAS DESINCORPORADAS Y/O DE MERCANCIAS IMPORTADAS TEMPORALMENTE DESTINADAS Y PROVENIENTES DEL BUQUE DE PERFORACIÓN DE DOBLE ACTIVIDAD EN AGUAS ULTRA PROFUNDAS';
            if (rigName) {
                documentTitle += ' DENOMINADO ' + rigName.toUpperCase();
            }
            if (rigImo) {
                documentTitle += ', CON NÚMERO IMO ' + rigImo;
            }
            if (rigField) {
                documentTitle += ', POSICIONADO EN EL CAMPO ' + rigField.toUpperCase();
            }
            documentTitle += '.';
        }
        var titleFit = fitTextToBox(documentTitle, boldFont, 9.6, 8.2, 506, 50);
        drawLines(page, titleFit.lines, 54, 199, 506, boldFont, titleFit.size, titleFit.lineHeight);

        var locationDate = cleanText(formData.locationDate || details.location_date_text || '');
        if (locationDate) {
            var locationLines = wrapText(locationDate, font, 9.6, 260);
            drawLines(page, locationLines, 300, 260, 260, font, 9.6, 12, null, 'right');
        }

        var introParts = [formData.introduction || details.introduction || '', formData.body || details.body || '', formData.operations || details.operations || '']
            .map(function (value) { return cleanText(expandDocumentTokens(value, tokenValues)); })
            .filter(function (value) { return value !== ''; });
        var introText = introParts.join('\n\n');
        var introFit = fitTextToBox(introText, font, 9.6, 7.2, 506, 194);
        var introHeight = introFit.lines.length * introFit.lineHeight;
        var introNeedsOwnPage = introHeight > 194.5;
        if (introNeedsOwnPage) {
            introFit = fitTextToBox(introText, font, 9.2, 6.4, 506, 405);
        }
        drawLines(page, introFit.lines, 54, 282, 506, font, introFit.size, introFit.lineHeight);

        var groups = groupItems(items);
        var currentPage = page;
        var currentTop;
        if (introNeedsOwnPage) {
            currentPage = createTemplatePage(outputPdf, embeddedTemplate);
            currentTop = drawTableHeader(currentPage, boldFont, CONTINUATION_TOP);
        } else {
            currentTop = drawTableHeader(page, boldFont);
        }

        groups.forEach(function (group, groupIndex) {
            var manifestLines = wrapText(cleanText(details.manifiesto || noticeNumber || ''), font, 6.7, TABLE_X[1] - TABLE_X[0] - 10);
            var importerLines = wrapText(cleanText(group.importer_name || 'N/D'), font, 6.7, TABLE_X[2] - TABLE_X[1] - 10);
            var merchLines = wrapText(buildMerchandiseText(group, aviso), font, 6.0, TABLE_X[3] - TABLE_X[2] - 10);
            var landingLines = wrapText(buildLandingText(details, groupIndex === 0, groupIndex > 0), italicFont, 6.0, TABLE_X[4] - TABLE_X[3] - 10);

            var merchOffset = 0;
            var landingOffset = 0;
            var firstSegment = true;

            while (merchOffset < merchLines.length || landingOffset < landingLines.length || firstSegment) {
                var availableHeight = TABLE_BOTTOM - currentTop;
                var lineHeight = 7.0;
                var maxLines = Math.floor((availableHeight - 8) / lineHeight);
                var repeatedMinimum = Math.max(manifestLines.length, importerLines.length, 1);

                if (maxLines < repeatedMinimum + 1) {
                    currentPage = createTemplatePage(outputPdf, embeddedTemplate);
                    currentTop = CONTINUATION_TOP;
                    availableHeight = TABLE_BOTTOM - currentTop;
                    maxLines = Math.floor((availableHeight - 8) / lineHeight);
                }

                var takeLines = Math.max(repeatedMinimum, maxLines);
                var merchSegment = merchLines.slice(merchOffset, merchOffset + takeLines);
                var landingSegment = landingLines.slice(landingOffset, landingOffset + takeLines);
                if (!merchSegment.length && merchOffset >= merchLines.length) {
                    merchSegment = [];
                }
                if (!landingSegment.length && landingOffset >= landingLines.length) {
                    landingSegment = [];
                }

                var rowLineCount = Math.max(repeatedMinimum, merchSegment.length, landingSegment.length, 1);
                var rowHeight = Math.max(28, rowLineCount * lineHeight + 8);
                if (rowHeight > availableHeight + 0.5) {
                    var forcedLines = Math.max(repeatedMinimum, Math.floor((availableHeight - 8) / lineHeight));
                    merchSegment = merchLines.slice(merchOffset, merchOffset + forcedLines);
                    landingSegment = landingLines.slice(landingOffset, landingOffset + forcedLines);
                    rowLineCount = Math.max(repeatedMinimum, merchSegment.length, landingSegment.length, 1);
                    rowHeight = Math.max(28, rowLineCount * lineHeight + 8);
                }

                drawTableSegment(currentPage, currentTop, rowHeight, {
                    manifestLines: manifestLines,
                    importerLines: importerLines,
                    merchLines: merchSegment,
                    landingLines: landingSegment
                }, fonts);

                merchOffset += merchSegment.length;
                landingOffset += landingSegment.length;
                currentTop += rowHeight;
                firstSegment = false;

                if (merchOffset < merchLines.length || landingOffset < landingLines.length) {
                    currentPage = createTemplatePage(outputPdf, embeddedTemplate);
                    currentTop = CONTINUATION_TOP;
                }
            }
        });

        if (photos.length) {
            var annexResult = await renderPhotoAnnex(outputPdf, embeddedTemplate, fonts, details, items, photos);
            if (annexResult && annexResult.page) {
                currentPage = annexResult.page;
                currentTop = annexResult.top;
            }
        }

        var documentation = cleanText(formData.documentation || details.documentation || '');
        if (!documentation) {
            documentation = buildDocumentation(details, groups);
        } else if (documentation.indexOf('MANIFIESTO') === -1 && cleanText(details.manifiesto)) {
            documentation += '\n' + buildDocumentation(details, groups).split('\n').slice(1).join('\n');
        }

        var closing = cleanText(formData.closing || details.closing_text || '');
        var signerName = cleanText(formData.signerName || details.signer_name || '');
        var signerTitle = cleanText(formData.signerTitle || details.signer_title || '');

        var requiredSpace = 165;
        if (TABLE_BOTTOM - currentTop < requiredSpace) {
            currentPage = createTemplatePage(outputPdf, embeddedTemplate);
            currentTop = 135;
        } else {
            currentTop += 18;
        }

        var docResult = drawWrapped(currentPage, documentation, 54, currentTop, 506, font, 9.5, 12, {});
        currentTop += docResult.height + 14;

        if (closing) {
            var closingResult = drawWrapped(currentPage, closing, 54, currentTop, 506, font, 9.5, 12, {});
            currentTop += closingResult.height + 18;
        }

        drawWrapped(currentPage, 'Atentamente', 54, currentTop, 506, font, 9.5, 12, { align: 'center' });
        currentTop += 24;
        if (signerName) {
            drawWrapped(currentPage, signerName, 54, currentTop, 506, font, 9.5, 12, { align: 'center' });
            currentTop += 16;
        }
        if (signerTitle) {
            drawWrapped(currentPage, signerTitle, 54, currentTop, 506, boldFont, 9.5, 12, { align: 'center' });
        }

        outputPdf.setTitle('Aviso de desembarque ' + (noticeNumber || ''));
        outputPdf.setCreator('Desembarques - Grupo Gerez');
        outputPdf.setProducer('pdf-lib');

        var bytes = await outputPdf.save();
        var blob = new Blob([bytes], { type: 'application/pdf' });
        var safeName = (noticeNumber || 'aviso').replace(/[^a-z0-9_-]+/gi, '-').replace(/^-+|-+$/g, '');
        var filename = 'aviso-desembarque-' + (safeName || 'documento') + '.pdf';
        var result = {
            success: true,
            blob: blob,
            bytes: bytes,
            filename: filename,
            pageCount: typeof outputPdf.getPageCount === 'function' ? outputPdf.getPageCount() : null,
            noticeNumber: noticeNumber || ''
        };

        if (!options || options.autoDownload !== false) {
            downloadResult(result);
        }

        return result;
    }

    function downloadResult(result) {
        if (!result || !result.blob) {
            return false;
        }

        var url = URL.createObjectURL(result.blob);
        var anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = cleanText(result.filename || 'aviso-desembarque.pdf') || 'aviso-desembarque.pdf';
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();
        window.setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
        return true;
    }

    window.AvisoPdfGenerator = {
        parseExcel: parseExcel,
        matchImporters: matchImporters,
        groupItems: groupItems,
        formatSpanishDate: formatSpanishDate,
        download: downloadResult,
        generate: generate
    };
}(window));
