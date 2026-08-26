(function (window) {
    'use strict';

    function text(es, en) {
        return window.AppI18n && typeof window.AppI18n.text === 'function'
            ? window.AppI18n.text(es, en)
            : ((document.documentElement.lang || 'es').toLowerCase() === 'en' ? en : es);
    }

    function cleanText(value) {
        return String(value === null || typeof value === 'undefined' ? '' : value)
            .replace(/\u00a0/g, ' ')
            .replace(/[\t ]+/g, ' ')
            .trim();
    }

    function spanishDate(value) {
        var text = cleanText(value);
        var match = text.match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (!match) {
            return text;
        }
        var months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        var monthIndex = Math.max(0, Math.min(11, Number(match[2]) - 1));
        return Number(match[3]) + ' de ' + months[monthIndex] + ' de ' + match[1];
    }

    function transformNarrative(value) {
        var text = String(value || '');
        if (!text) {
            return '';
        }

        text = text.replace(/Presento\s+Aviso\s+de\s+DESEMBARQUE/gi, 'Presento Alcance de Aviso de DESEMBARQUE');
        text = text.replace(/Presento\s+el\s+Aviso\s+de\s+DESEMBARQUE/gi, 'Presento Alcance de Aviso de DESEMBARQUE');
        return text;
    }

    function buildTitle(options) {
        var opts = options || {};
        var code = cleanText(opts.documentCode || '');
        var detail = opts.detail || {};
        var rigName = cleanText(detail.rig_name || '');
        var rigImo = cleanText(detail.rig_imo || '');
        var rigField = cleanText(detail.rig_field || '');
        var receipt = opts.parentReceipt || null;

        var title = 'SE PRESENTA ALCANCE AL AVISO DE DESEMBARQUE';
        if (code) {
            title += ' NUMERO ' + code.toUpperCase();
        }
        if (receipt && cleanText(receipt.folio) && cleanText(receipt.date)) {
            title += ' EN RELACION AL ACUSE DE NO. DE FOLIO ' + cleanText(receipt.folio).toUpperCase()
                + ' DE FECHA ' + spanishDate(receipt.date).toUpperCase();
        }
        title += ', DE CONFORMIDAD CON LOS PARRAFOS CUARTO Y QUINTO DE LA REGLA 4.2.11 DE LAS REGLAS GENERALES DE COMERCIO EXTERIOR. DE MERCANCIAS DESINCORPORADAS Y/O DE MERCANCIAS IMPORTADAS TEMPORALMENTE DESTINADAS Y PROVENIENTES DEL BUQUE DE PERFORACIÓN DE DOBLE ACTIVIDAD EN AGUAS ULTRA PROFUNDAS';
        if (rigName) {
            title += ' DENOMINADO ' + rigName.toUpperCase();
        }
        if (rigImo) {
            title += ', CON NÚMERO IMO ' + rigImo;
        }
        if (rigField) {
            title += ', POSICIONADO EN EL CAMPO ' + rigField.toUpperCase();
        }
        title += '.';
        return title;
    }

    function itemPedimentos(item) {
        var relations = Array.isArray(item && item.pedimentos) ? item.pedimentos.filter(Boolean) : [];
        if (relations.length) {
            return relations.map(function (relation) {
                return {
                    clave: cleanText(relation.key || relation.clave || '').toUpperCase(),
                    numero: cleanText(relation.number || relation.num_pedimento || relation.pedimento || '')
                };
            }).filter(function (relation) { return relation.numero; });
        }
        var legacyNumber = cleanText(item && item.num_pedimento);
        return legacyNumber ? [{ clave: cleanText(item && item.clave).toUpperCase(), numero: legacyNumber }] : [];
    }

    function uniquePedimentos(items) {
        var seen = {};
        var result = [];
        (Array.isArray(items) ? items : []).forEach(function (item) {
            itemPedimentos(item).forEach(function (pedimento) {
                var key = pedimento.numero.replace(/\D+/g, '');
                if (!key || seen[key]) {
                    return;
                }
                seen[key] = true;
                result.push(pedimento);
            });
        });
        return result;
    }

    function buildAlcanceIntroduction(detail) {
        var rigName = cleanText(detail && detail.rig_name || '');
        var rigImo = cleanText(detail && detail.rig_imo || '');
        var rigField = cleanText(detail && detail.rig_field || '');
        var comitente = cleanText(detail && detail.comitente || '');

        var paragraph = 'Quien suscribe el presente Javier Gerez Bazan, Mexicano, mayor de edad, con Registro Federal de Contribuyentes GEBJ8001191K9, con domicilio fiscal ubicado en calle Saturno #100, Col. Anáhuac, C.P. 89180, Tampico Tamaulipas y domicilio dentro de la circunscripción territorial de esa Aduana de Tampico, para oír y recibir notificaciones el declarado como domicilio fiscal, con teléfono (833) 214-00-41, de ocupación Agente Aduanal, con número de Patente Nacional 1948 con autorización para actuar ante la Aduana de Altamira, Tampico y Matamoros por este conducto, en los términos del artículo 8 de la Constitución Política de los Estados Unidos Mexicanos, artículos 18 y 18-A del Código Fiscal de la Federación, artículo 41 de la Ley Aduanera, autorizando en los términos del artículo 19, cuarto párrafo del Código Fiscal de la Federación a los C. Víctor Cándido Ibarias Toledo gafete número 95045, Daniel Iván Guadalupe Nieto gafete número 95771, Juan Pablo Cañizares Soto gafete número 270 y Filiberto Salas González gafete número 96247, todos ellos dependientes autorizados de mi patente 1948 y correos electrónicos kevin.guzman@grupogerez.com, victor.ibarias@grupogerez.com, y teléfonos (833) 2601033 y (833) 2140041, ante usted con el debido respeto, mediante el presente, con fundamento en el artículo 41 de la Ley Aduanera, en nombre de mi comitente';

        if (comitente) {
            paragraph += ' ' + comitente.toUpperCase();
        }
        paragraph += ', Presento Alcance de Aviso de DESEMBARQUE de conformidad con los párrafos cuarto y quinto de la regla 4.2.11 de las reglas generales de comercio exterior, de mercancías desincorporadas y/o de mercancías importadas temporalmente destinadas y provenientes del buque de perforación de doble actividad en aguas ultra profundas denominado';
        if (rigName) {
            paragraph += ' ' + rigName.toUpperCase();
        }
        if (rigImo) {
            paragraph += ', con número IMO ' + rigImo;
        }
        if (rigField) {
            paragraph += ', posicionado en el campo ' + rigField.toUpperCase();
        }
        paragraph += ', para lo anterior se declara la siguiente información:';
        return paragraph;
    }

    function buildAlcanceClosing() {
        return 'Por lo antes expuesto, respetuosamente en espera de que el presente cumpla con la normatividad vigente para efectos legales de lo que en el presente se declara, agradezco la atención que brinde al presente, quedando a sus órdenes para cualquier duda o aclaración al respecto.';
    }

    function buildDocumentation(detail, items) {
        var manifest = cleanText(detail && detail.manifiesto) || 'N/A';
        var lines = [
            'Por lo anterior, se anexa al presente el MANIFIESTO DE CARGA ACTUALIZADO ' + manifest,
            'Documentos que se anexan:'
        ];
        uniquePedimentos(items).forEach(function (pedimento) {
            var keyText = pedimento.clave ? ' CLAVE ' + pedimento.clave : '';
            lines.push('PEDIMENTO DE IMPORTACION TEMPORAL' + keyText + ' NUMERO: ' + pedimento.numero);
        });
        return lines.join('\n');
    }

    async function generate(options) {
        if (!window.AvisoPdfGenerator || typeof window.AvisoPdfGenerator.generate !== 'function') {
            throw new Error(text('El generador base del Aviso no está disponible.', 'The base notice generator is not available.'));
        }
        if (!window.PDFLib || !window.PDFLib.PDFDocument) {
            throw new Error(text('PDF-lib no está disponible.', 'PDF-lib is not available.'));
        }

        var opts = options || {};
        var detail = Object.assign({}, opts.detail || {});
        var items = Array.isArray(opts.items) ? opts.items : [];
        var photos = Array.isArray(opts.photos) ? opts.photos : [];
        if (!items.length) {
            throw new Error(text('Selecciona al menos una mercancía para el Alcance.', 'Select at least one merchandise line for the addendum.'));
        }

        var documentCode = cleanText(opts.documentCode || detail.document_code || '');
        var noticeNumber = cleanText(opts.noticeNumber || detail.notice_number || detail.manifiesto || '');
        var alcanceDate = cleanText(opts.alcanceDate || '');
        var alcanceNo = Math.max(1, Number(opts.alcanceNo || 1));

        var formData = {
            documentCode: documentCode,
            noticeNumber: noticeNumber,
            recipient: cleanText(detail.recipient_text || ''),
            documentTitle: buildTitle({
                documentCode: documentCode,
                detail: detail,
                parentReceipt: opts.parentReceipt || null
            }),
            locationDate: 'Tampico, Tamaulipas a ' + spanishDate(alcanceDate),
            rigName: cleanText(detail.rig_name || ''),
            rigImo: cleanText(detail.rig_imo || ''),
            rigField: cleanText(detail.rig_field || ''),
            comitente: cleanText(detail.comitente || ''),
            introduction: buildAlcanceIntroduction(detail),
            body: '',
            operations: '',
            documentation: buildDocumentation(detail, items),
            closing: buildAlcanceClosing(),
            signerName: cleanText(detail.signer_name || ''),
            signerTitle: cleanText(detail.signer_title || '')
        };

        var baseResult = await window.AvisoPdfGenerator.generate({
            templateUrl: cleanText(opts.templateUrl || ''),
            formData: formData,
            aviso: {
                details: detail,
                items: items,
                photos: photos
            },
            autoDownload: false
        });

        var metadataPdf = await window.PDFLib.PDFDocument.load(baseResult.bytes);
        metadataPdf.setTitle('Alcance al Aviso de Desembarque ' + noticeNumber + ' #' + alcanceNo);
        metadataPdf.setSubject('Alcance al Aviso de Desembarque');
        metadataPdf.setCreator('Desembarques - Grupo Gerez');
        var bytes = await metadataPdf.save();
        var blob = new Blob([bytes], { type: 'application/pdf' });
        var safeNotice = (noticeNumber || 'aviso').replace(/[^a-z0-9_-]+/gi, '-').replace(/^-+|-+$/g, '');
        var filename = 'alcance-aviso-' + (safeNotice || 'documento') + '-a' + String(alcanceNo).padStart(2, '0') + '.pdf';

        return {
            success: true,
            bytes: bytes,
            blob: blob,
            filename: filename,
            pageCount: metadataPdf.getPageCount(),
            alcanceNo: alcanceNo,
            noticeNumber: noticeNumber
        };
    }

    function download(result) {
        if (!result || !result.blob) {
            return false;
        }
        var url = URL.createObjectURL(result.blob);
        var link = document.createElement('a');
        link.href = url;
        link.download = result.filename || 'alcance.pdf';
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
        return true;
    }

    window.AlcancePdfGenerator = {
        generate: generate,
        download: download
    };
}(window));
