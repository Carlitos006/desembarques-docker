(function (window, document) {
    'use strict';

    var app = window.AppConfig || {};
    var expediente = app.avisoExpediente || {};
    var cfg = expediente.alcances || {};
    var language = app.language === 'en' ? 'en' : 'es';
    function text(es, en) {
        return window.AppI18n && typeof window.AppI18n.text === 'function'
            ? window.AppI18n.text(es, en)
            : (language === 'en' ? en : es);
    }
    if (!cfg.enabled) {
        return;
    }

    function byId(id) {
        return document.getElementById(id);
    }

    function setFeedback(element, message, type) {
        if (!element) {
            return;
        }
        element.className = 'alert mt-3 mb-0 alert-' + (type || 'danger');
        element.textContent = message || '';
        element.classList.toggle('d-none', !message);
    }

    function selectedIds() {
        return Array.prototype.slice.call(document.querySelectorAll('.alcance-item-check:checked')).map(function (input) {
            return Number(input.value || 0);
        }).filter(function (value) { return value > 0; });
    }

    function itemMap() {
        var map = {};
        (Array.isArray(cfg.items) ? cfg.items : []).forEach(function (item) {
            map[Number(item.id || 0)] = item;
        });
        return map;
    }

    var itemsById = itemMap();

    function itemsForIds(ids) {
        return ids.map(function (id) { return itemsById[id]; }).filter(Boolean);
    }

    function photosForIds(ids) {
        var selected = {};
        ids.forEach(function (id) { selected[id] = true; });
        return (Array.isArray(cfg.photos) ? cfg.photos : []).filter(function (photo) {
            var itemId = Number(photo.aviso_item_id || 0);
            return itemId === 0 || Boolean(selected[itemId]);
        });
    }

    async function createPdf(ids, alcanceDate, alcanceNo) {
        return window.AlcancePdfGenerator.generate({
            templateUrl: cfg.templateUrl,
            detail: cfg.detail || {},
            documentCode: cfg.documentCode || '',
            noticeNumber: cfg.noticeNumber || '',
            alcanceDate: alcanceDate,
            alcanceNo: alcanceNo,
            items: itemsForIds(ids),
            photos: photosForIds(ids),
            parentReceipt: cfg.parentReceipt || null
        });
    }

    async function postPdf(endpoint, fields, result) {
        var formData = new FormData();
        Object.keys(fields).forEach(function (key) {
            formData.append(key, fields[key]);
        });
        formData.append('pdf', result.blob, result.filename);
        var response = await fetch(endpoint, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            cache: 'no-store'
        });
        var payload = null;
        try {
            payload = await response.json();
        } catch (error) {
            payload = null;
        }
        if (!response.ok || !payload || payload.success !== true) {
            throw new Error(payload && payload.message ? payload.message : text('No fue posible guardar el Alcance.', 'The addendum could not be saved.'));
        }
        return payload;
    }

    var generateForm = byId('alcance-generate-form');
    if (generateForm) {
        generateForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            var feedback = byId('alcance-generate-feedback');
            var submit = byId('alcance-generate-submit');
            var ids = selectedIds();
            if (!ids.length) {
                setFeedback(feedback, text('Selecciona al menos una mercancía para el Alcance.', 'Select at least one merchandise line for the addendum.'), 'warning');
                return;
            }
            var alcanceDate = String((byId('alcance-date') || {}).value || '').trim();
            if (!alcanceDate) {
                setFeedback(feedback, text('Indica la fecha del Alcance.', 'Enter the addendum date.'), 'warning');
                return;
            }

            try {
                if (submit) {
                    submit.disabled = true;
                    submit.textContent = text('Generando...', 'Generating...');
                }
                setFeedback(feedback, '', 'info');
                var result = await createPdf(ids, alcanceDate, Number(cfg.nextAlcanceNo || 1));
                var payload = await postPdf(cfg.generateEndpoint, {
                    csrf_token: expediente.csrfToken || '',
                    desembarque_id: String(expediente.desembarqueId || 0),
                    alcance_date: alcanceDate,
                    notes: String((byId('alcance-notes') || {}).value || '').trim(),
                    item_ids: JSON.stringify(ids),
                    page_count: String(result.pageCount || 1)
                }, result);
                window.AlcancePdfGenerator.download(result);
                window.location.href = 'aviso-expediente.php?id=' + encodeURIComponent(expediente.desembarqueId) + '&tab=alcances&alcance_created=1';
            } catch (error) {
                setFeedback(feedback, error && error.message ? error.message : text('No fue posible generar el Alcance.', 'The addendum could not be generated.'), 'danger');
            } finally {
                if (submit) {
                    submit.disabled = false;
                    submit.textContent = text('Generar PDF y archivar', 'Generate PDF and archive');
                }
            }
        });
    }

    document.addEventListener('click', async function (event) {
        var button = event.target.closest('[data-alcance-new-version]');
        if (!button) {
            return;
        }
        event.preventDefault();
        var alcanceId = Number(button.getAttribute('data-alcance-id') || 0);
        var alcanceNo = Number(button.getAttribute('data-alcance-no') || 1);
        var alcanceDate = String(button.getAttribute('data-alcance-date') || '').trim();
        var ids = [];
        try {
            ids = JSON.parse(button.getAttribute('data-item-ids') || '[]').map(Number).filter(function (value) { return value > 0; });
        } catch (error) {
            ids = [];
        }
        if (!alcanceId || !ids.length) {
            window.alert(text('El Alcance no tiene mercancías válidas para regenerarse.', 'The addendum has no valid merchandise lines to regenerate.'));
            return;
        }
        if (!window.confirm(text('Se generará una nueva versión inmutable de este Alcance. ¿Continuar?', 'A new immutable version of this addendum will be generated. Continue?'))) {
            return;
        }

        var originalText = button.textContent;
        button.disabled = true;
        button.textContent = text('Generando...', 'Generating...');
        try {
            var result = await createPdf(ids, alcanceDate, alcanceNo);
            await postPdf(cfg.versionEndpoint, {
                csrf_token: expediente.csrfToken || '',
                alcance_id: String(alcanceId),
                page_count: String(result.pageCount || 1)
            }, result);
            window.AlcancePdfGenerator.download(result);
            window.location.href = 'aviso-expediente.php?id=' + encodeURIComponent(expediente.desembarqueId) + '&tab=alcances&alcance_versioned=1';
        } catch (error) {
            window.alert(error && error.message ? error.message : text('No fue posible generar la nueva versión.', 'The new version could not be generated.'));
        } finally {
            button.disabled = false;
            button.textContent = originalText;
        }
    });

    var receiptModalElement = byId('alcanceReceiptModal');
    var receiptModal = receiptModalElement && window.bootstrap ? new window.bootstrap.Modal(receiptModalElement) : null;
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-alcance-receipt]');
        if (!button || !receiptModal) {
            return;
        }
        var existing = button.getAttribute('data-has-receipt') === '1';
        byId('alcance-receipt-id').value = button.getAttribute('data-alcance-id') || '';
        byId('alcance-receipt-folio').value = button.getAttribute('data-folio') || '';
        byId('alcance-receipt-received-at').value = button.getAttribute('data-received-at') || '';
        byId('alcance-receipt-notes').value = button.getAttribute('data-notes') || '';
        var reasonWrap = byId('alcance-receipt-correction-wrap');
        var reason = byId('alcance-receipt-correction-reason');
        if (reasonWrap) {
            reasonWrap.classList.toggle('d-none', !existing);
        }
        if (reason) {
            reason.value = '';
            reason.required = existing;
        }
        var title = byId('alcanceReceiptModalLabel');
        if (title) {
            title.textContent = existing
                ? text('Corregir acuse del Alcance', 'Correct addendum acknowledgment')
                : text('Registrar acuse del Alcance', 'Record addendum acknowledgment');
        }
        setFeedback(byId('alcance-receipt-feedback'), '', 'info');
        receiptModal.show();
    });

    var receiptForm = byId('alcance-receipt-form');
    if (receiptForm) {
        receiptForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            var feedback = byId('alcance-receipt-feedback');
            var submit = byId('alcance-receipt-submit');
            var formData = new FormData();
            formData.append('csrf_token', expediente.csrfToken || '');
            formData.append('alcance_id', String((byId('alcance-receipt-id') || {}).value || ''));
            formData.append('folio', String((byId('alcance-receipt-folio') || {}).value || '').trim());
            formData.append('received_at', String((byId('alcance-receipt-received-at') || {}).value || '').trim());
            formData.append('notes', String((byId('alcance-receipt-notes') || {}).value || '').trim());
            formData.append('correction_reason', String((byId('alcance-receipt-correction-reason') || {}).value || '').trim());
            var fileInput = byId('alcance-receipt-evidence');
            if (fileInput && fileInput.files && fileInput.files[0]) {
                formData.append('evidence', fileInput.files[0]);
            }

            submit.disabled = true;
            submit.textContent = text('Guardando...', 'Saving...');
            try {
                var response = await fetch(cfg.receiptEndpoint, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                    cache: 'no-store'
                });
                var payload = await response.json();
                if (!response.ok || !payload || payload.success !== true) {
                    throw new Error(payload && payload.message ? payload.message : text('No fue posible registrar el acuse.', 'The acknowledgment could not be recorded.'));
                }
                window.location.href = 'aviso-expediente.php?id=' + encodeURIComponent(expediente.desembarqueId) + '&tab=alcances&alcance_receipt=1';
            } catch (error) {
                setFeedback(feedback, error && error.message ? error.message : text('No fue posible registrar el acuse.', 'The acknowledgment could not be recorded.'), 'danger');
            } finally {
                submit.disabled = false;
                submit.textContent = text('Guardar acuse', 'Save acknowledgment');
            }
        });
    }
}(window, document));
