(function () {
    'use strict';

    var config = window.AppConfig && window.AppConfig.avisoExpediente
        ? window.AppConfig.avisoExpediente
        : null;

    if (!config) {
        return;
    }

    var language = (window.AppConfig && window.AppConfig.language) === 'en' ? 'en' : 'es';
    var maxSize = 25 * 1024 * 1024;
    var allowedExtensions = ['pdf', 'doc', 'docx'];

    function text(es, en) {
        return language === 'en' ? en : es;
    }

    function parseDocumentPayload(element, attributeName) {
        if (!element) {
            return null;
        }

        var raw = element.getAttribute(attributeName) || '';
        if (!raw) {
            return null;
        }

        try {
            var parsed = JSON.parse(raw);
            return parsed && typeof parsed === 'object' ? parsed : null;
        } catch (error) {
            console.error('Unable to parse document payload:', error);
            return null;
        }
    }

    function extensionOf(filename) {
        var parts = String(filename || '').toLowerCase().split('.');
        return parts.length > 1 ? parts.pop() : '';
    }

    function validateFiles(files, maxCount) {
        var list = Array.prototype.slice.call(files || []);
        if (!list.length) {
            return text('Selecciona al menos un documento.', 'Select at least one document.');
        }

        if (maxCount && list.length > maxCount) {
            return text('Puedes subir hasta ' + maxCount + ' documentos por operación.', 'You can upload up to ' + maxCount + ' documents per operation.');
        }

        for (var index = 0; index < list.length; index += 1) {
            var file = list[index];
            var extension = extensionOf(file.name);
            if (allowedExtensions.indexOf(extension) === -1) {
                return text('Sólo se aceptan archivos PDF, DOC y DOCX.', 'Only PDF, DOC and DOCX files are accepted.');
            }
            if (Number(file.size || 0) <= 0) {
                return text('Uno de los archivos está vacío.', 'One of the selected files is empty.');
            }
            if (Number(file.size || 0) > maxSize) {
                return text('Cada archivo debe pesar como máximo 10 MB.', 'Each file must be 10 MB or smaller.');
            }
        }

        return '';
    }

    function setFeedback(element, type, message) {
        if (!element) {
            return;
        }

        element.className = 'alert mt-3 mb-0 alert-' + type;
        element.textContent = message || '';
        element.classList.toggle('d-none', !message);
    }

    function setButtonBusy(button, busy, idleLabel, busyLabel) {
        if (!button) {
            return;
        }
        button.disabled = Boolean(busy);
        button.textContent = busy ? busyLabel : idleLabel;
    }

    function readJsonResponse(response) {
        return response.json().catch(function () {
            return {};
        }).then(function (payload) {
            if (!response.ok || !payload || payload.success !== true) {
                var error = new Error(payload && payload.message ? payload.message : text('La operación no pudo completarse.', 'The operation could not be completed.'));
                error.status = response.status;
                error.payload = payload;
                throw error;
            }
            return payload;
        });
    }

    function redirectToDocuments(action) {
        var url = new URL(window.location.href);
        url.searchParams.set('id', String(config.desembarqueId || ''));
        url.searchParams.set('tab', 'documentos');
        if (action) {
            url.searchParams.set('doc_action', action);
        } else {
            url.searchParams.delete('doc_action');
        }
        window.location.href = url.toString();
    }

    var uploadForm = document.getElementById('document-upload-form');
    var uploadFiles = document.getElementById('document-upload-files');
    var uploadFeedback = document.getElementById('document-upload-feedback');
    var uploadSubmit = document.getElementById('document-upload-submit');

    if (uploadForm) {
        uploadForm.addEventListener('submit', function (event) {
            event.preventDefault();

            var validationError = validateFiles(uploadFiles && uploadFiles.files, 10);
            if (validationError) {
                setFeedback(uploadFeedback, 'warning', validationError);
                return;
            }

            setFeedback(uploadFeedback, 'info', text('Guardando documentos…', 'Saving documents…'));
            setButtonBusy(uploadSubmit, true, text('Guardar documentos', 'Save documents'), text('Guardando…', 'Saving…'));

            fetch(config.uploadEndpoint, {
                method: 'POST',
                body: new FormData(uploadForm),
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).then(readJsonResponse).then(function () {
                redirectToDocuments('uploaded');
            }).catch(function (error) {
                setFeedback(uploadFeedback, 'danger', error && error.message ? error.message : text('No fue posible guardar los documentos.', 'Unable to save the documents.'));
                setButtonBusy(uploadSubmit, false, text('Guardar documentos', 'Save documents'), text('Guardando…', 'Saving…'));
            });
        });
    }

    var replaceModalElement = document.getElementById('documentReplaceModal');
    var replaceModal = replaceModalElement && window.bootstrap
        ? window.bootstrap.Modal.getOrCreateInstance(replaceModalElement)
        : null;
    var replaceForm = document.getElementById('document-replace-form');
    var replaceId = document.getElementById('document-replace-id');
    var replaceType = document.getElementById('document-replace-type');
    var replaceDate = document.getElementById('document-replace-date');
    var replaceFile = document.getElementById('document-replace-file');
    var replaceDescription = document.getElementById('document-replace-description');
    var replaceCurrent = document.getElementById('document-replace-current');
    var replaceFeedback = document.getElementById('document-replace-feedback');
    var replaceSubmit = document.getElementById('document-replace-submit');

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-document-replace]');
        if (!button) {
            return;
        }

        var documentData = parseDocumentPayload(button, 'data-document-replace');
        if (!documentData || !replaceModal) {
            return;
        }

        if (replaceId) {
            replaceId.value = String(documentData.id || '');
        }
        if (replaceType) {
            replaceType.value = String(documentData.document_type || 'other');
        }
        if (replaceDate) {
            replaceDate.value = String(documentData.document_date || '');
        }
        if (replaceDescription) {
            replaceDescription.value = String(documentData.description || '');
        }
        if (replaceFile) {
            replaceFile.value = '';
        }
        if (replaceCurrent) {
            replaceCurrent.textContent = text('Documento vigente: ', 'Current document: ') + String(documentData.name || '');
        }
        setFeedback(replaceFeedback, 'info', '');
        replaceModal.show();
    });

    if (replaceForm) {
        replaceForm.addEventListener('submit', function (event) {
            event.preventDefault();

            var validationError = validateFiles(replaceFile && replaceFile.files, 1);
            if (validationError) {
                setFeedback(replaceFeedback, 'warning', validationError);
                return;
            }

            setFeedback(replaceFeedback, 'info', text('Guardando nueva versión…', 'Saving new version…'));
            setButtonBusy(replaceSubmit, true, text('Guardar nueva versión', 'Save new version'), text('Guardando…', 'Saving…'));

            fetch(config.replaceEndpoint, {
                method: 'POST',
                body: new FormData(replaceForm),
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).then(readJsonResponse).then(function () {
                redirectToDocuments('replaced');
            }).catch(function (error) {
                setFeedback(replaceFeedback, 'danger', error && error.message ? error.message : text('No fue posible reemplazar el documento.', 'Unable to replace the document.'));
                setButtonBusy(replaceSubmit, false, text('Guardar nueva versión', 'Save new version'), text('Guardando…', 'Saving…'));
            });
        });
    }

    var detailsModalElement = document.getElementById('documentDetailsModal');
    var detailsModal = detailsModalElement && window.bootstrap
        ? window.bootstrap.Modal.getOrCreateInstance(detailsModalElement)
        : null;
    var detailsName = document.getElementById('document-details-name');
    var detailsType = document.getElementById('document-details-type');
    var detailsStatus = document.getElementById('document-details-status');
    var detailsDate = document.getElementById('document-details-date');
    var detailsUploaded = document.getElementById('document-details-uploaded');
    var detailsUser = document.getElementById('document-details-user');
    var detailsFile = document.getElementById('document-details-file');
    var detailsDescription = document.getElementById('document-details-description');
    var detailsHash = document.getElementById('document-details-hash');
    var detailsReplacementWrap = document.getElementById('document-details-replacement-wrap');
    var detailsReplacement = document.getElementById('document-details-replacement');
    var detailsDownload = document.getElementById('document-details-download');
    var verifyButton = document.getElementById('document-verify-button');
    var verifyFeedback = document.getElementById('document-verify-feedback');
    var currentDetailsDocument = null;

    function displayValue(value) {
        var normalized = String(value == null ? '' : value).trim();
        return normalized || '—';
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-document-details]');
        if (!button) {
            return;
        }

        var documentData = parseDocumentPayload(button, 'data-document-details');
        if (!documentData || !detailsModal) {
            return;
        }

        currentDetailsDocument = documentData;
        detailsName.textContent = displayValue(documentData.name);
        detailsType.textContent = displayValue(documentData.document_type_label) + ' · ' + displayValue(documentData.format);
        detailsStatus.textContent = documentData.is_active
            ? text('VIGENTE', 'CURRENT')
            : text('REEMPLAZADO', 'REPLACED');
        detailsDate.textContent = displayValue(documentData.document_date);
        detailsUploaded.textContent = displayValue(documentData.created_at);
        detailsUser.textContent = displayValue(documentData.uploaded_by);
        detailsFile.textContent = displayValue(documentData.size_label) + ' · ' + displayValue(documentData.mime_type);
        detailsDescription.textContent = displayValue(documentData.description);
        detailsHash.textContent = displayValue(documentData.sha256);
        detailsDownload.href = config.downloadEndpoint + '?id=' + encodeURIComponent(String(documentData.id || '')) + (config.viewDeleted ? '&deleted=1' : '');

        var replacementParts = [];
        if (documentData.replaces_file_id) {
            replacementParts.push(text('Reemplaza al documento #', 'Replaces document #') + String(documentData.replaces_file_id));
        }
        if (documentData.replacement_name) {
            replacementParts.push(text('Reemplazado por ', 'Replaced by ') + String(documentData.replacement_name));
        }
        if (documentData.replaced_at && documentData.replaced_at !== '—') {
            replacementParts.push(text('Fecha de reemplazo: ', 'Replacement date: ') + String(documentData.replaced_at));
        }
        if (detailsReplacementWrap && detailsReplacement) {
            detailsReplacementWrap.classList.toggle('d-none', !replacementParts.length);
            detailsReplacement.textContent = replacementParts.length ? replacementParts.join(' · ') : '—';
        }

        if (verifyButton) {
            verifyButton.dataset.fileId = String(documentData.id || '');
            verifyButton.disabled = false;
        }
        setFeedback(verifyFeedback, 'info', '');
        detailsModal.show();
    });

    if (verifyButton) {
        verifyButton.addEventListener('click', function () {
            var fileId = Number(verifyButton.dataset.fileId || 0);
            if (!fileId) {
                return;
            }

            verifyButton.disabled = true;
            setFeedback(verifyFeedback, 'info', text('Verificando archivo almacenado…', 'Verifying stored file…'));

            fetch(config.verifyEndpoint + '?id=' + encodeURIComponent(String(fileId)) + (config.viewDeleted ? '&deleted=1' : ''), {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (payload) {
                    if (!response.ok || !payload || payload.success !== true) {
                        throw new Error(payload && payload.message ? payload.message : text('No fue posible verificar la integridad.', 'Unable to verify integrity.'));
                    }
                    return payload;
                });
            }).then(function (payload) {
                var feedbackType = payload.verified ? 'success' : (payload.has_baseline ? 'danger' : 'warning');
                setFeedback(verifyFeedback, feedbackType, payload.message || '');
                if (payload.expected_sha256 && detailsHash) {
                    detailsHash.textContent = payload.expected_sha256;
                }
                if (currentDetailsDocument && payload.expected_sha256) {
                    currentDetailsDocument.sha256 = payload.expected_sha256;
                }
            }).catch(function (error) {
                setFeedback(verifyFeedback, 'danger', error && error.message ? error.message : text('No fue posible verificar la integridad.', 'Unable to verify integrity.'));
            }).finally(function () {
                verifyButton.disabled = false;
            });
        });
    }

    // Preserve the selected tab in the URL without a reload.
    document.querySelectorAll('#expedienteTabs [data-bs-toggle="pill"]').forEach(function (tabButton) {
        tabButton.addEventListener('shown.bs.tab', function (event) {
            var target = event.target.getAttribute('data-bs-target') || '';
            if (target.indexOf('#panel-') !== 0) {
                return;
            }
            var tab = target.slice('#panel-'.length);
            var url = new URL(window.location.href);
            url.searchParams.set('tab', tab);
            url.searchParams.delete('doc_action');
            window.history.replaceState({}, '', url.toString());
        });
    });
}());
