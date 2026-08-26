(function ($) {
    'use strict';

    var appConfig = window.AppConfig || {};
    var translations = appConfig.translations || {};
    var currentLanguage = appConfig.language || 'es';
    var offlineQueue = window.DesembarquesOfflineQueue || null;
    var offlineQueueSupported = !!(offlineQueue
        && typeof offlineQueue.isSupported === 'function'
        && offlineQueue.isSupported());
    var fileUploadConfig = appConfig.fileUpload || {};
    var maxAttachmentSize = Number(fileUploadConfig.maxSize || 0);
    var maxAttachmentsPerRequest = Number(fileUploadConfig.maxFilesPerRequest || 0);
    var allowedExtensions = Array.isArray(fileUploadConfig.allowedExtensions)
        ? fileUploadConfig.allowedExtensions.map(function (extension) {
            return String(extension || '').toLowerCase();
        })
        : [];
    var allowedExtensionsSet = {};

    allowedExtensions.forEach(function (extension) {
        if (extension) {
            allowedExtensionsSet[extension] = true;
        }
    });

    function translate(key, fallback, replacements) {
        var value;

        if (Object.prototype.hasOwnProperty.call(translations, key)) {
            value = translations[key];
        } else {
            value = fallback;
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

    function parseDate(dateString) {
        if (!dateString) {
            return null;
        }

        var parts = dateString.split('-');
        if (parts.length !== 3) {
            return null;
        }

        var year = parseInt(parts[0], 10);
        var monthIndex = parseInt(parts[1], 10) - 1;
        var day = parseInt(parts[2], 10);

        if (Number.isNaN(year) || Number.isNaN(monthIndex) || Number.isNaN(day)) {
            return null;
        }

        return new Date(Date.UTC(year, monthIndex, day));
    }

    function calculateDaysDifference(laterDate, earlierDate) {
        if (!(laterDate instanceof Date) || Number.isNaN(laterDate) || !(earlierDate instanceof Date) || Number.isNaN(earlierDate)) {
            return null;
        }

        var millisecondsInDay = 24 * 60 * 60 * 1000;
        var diff = laterDate.getTime() - earlierDate.getTime();
        return Math.round(diff / millisecondsInDay);
    }

    $(function () {
        var $form = $('#desembarque-form');
        var $fechaDesembarque = $('#fecha_desembarque');
        var $fechaEmbarque = $('#fecha_embarque');
        var $diasTranscurridos = $('#dias_transcurridos');
        var $diasFuera = $('#dias_fuera');
        var $diasTranscurridosDisplay = $('#dias_transcurridos_display');
        var $diasFueraDisplay = $('#dias_fuera_display');
        var $clienteInput = $('#cliente');
        var $clienteSelect = $('#cliente_id');
        var $clienteSelectInfo = $('#cliente-select-info');
        var clienteInfoTemplate = $clienteSelectInfo.attr('data-template') || '';
        var $referenciaInput = $('#referencia');
        var $statusSelect = $('#status_id');
        var $attachmentsInput = $('#attachments');
        var $attachmentsList = $('#attachments-list');
        var $attachmentsEmpty = $('#attachments-empty');
        var $attachmentsFeedback = $('#attachments-feedback');
        var attachments = [];
        var offlineQueueEndpoint = '../api/desembarques/store.php';
        var offlineQueueEnabled = offlineQueueSupported;
        var offlineSessionRedirectScheduled = false;
        var dataTransferSupported = typeof window.DataTransfer === 'function';
        var $body = $('body');
        var $mobileToggle = $('[data-mobile-mode-toggle]');
        var $mobileModeBanner = $('#mobile-mode-banner');
        var $mobileModeBadge = $('#mobile-mode-badge');
        var $mobileModeFeedback = $('#mobile-mode-feedback');
        var $mobileEvidenceTools = $('#mobile-evidence-tools');
        var $mobileModeHint = $('#mobile-mode-hint');
        var $mobilePhotoButton = $('#mobile-photo-button');
        var $mobilePhotoInput = $('#mobile-photo-input');
        var $mobilePhotoHelp = $('#mobile-photo-help');
        var $mobileAnnotationButton = $('#mobile-annotation-button');
        var $mobileAnnotationHelp = $('#mobile-annotation-help');
        var $mobileAnnotationModal = $('#mobile-annotation-modal');
        var $annotationImageSelect = $('#annotation-image-select');
        var $annotationEmptyState = $('#annotation-empty-state');
        var $annotationCanvasWrapper = $('#annotation-canvas-wrapper');
        var $annotationCanvas = $('#annotation-canvas');
        var $annotationDrawHint = $('#annotation-draw-hint');
        var annotationCanvasElement = $annotationCanvas.length ? $annotationCanvas[0] : null;
        var annotationContext = annotationCanvasElement && annotationCanvasElement.getContext ? annotationCanvasElement.getContext('2d') : null;
        var $annotationColorInput = $('#annotation-color');
        var $annotationClear = $('#annotation-clear');
        var $annotationSave = $('#annotation-save');
        var $mobileBarcodeButton = $('#mobile-barcode-button');
        var $mobileBarcodeHelp = $('#mobile-barcode-help');
        var $barcodeModal = $('#barcode-scanner-modal');
        var $barcodeUnsupportedAlert = $('#barcode-unsupported-alert');
        var $barcodeScannerContainer = $('#barcode-scanner-container');
        var barcodeVideoElement = document.getElementById('barcode-scanner-video');
        var $barcodeScannerHelp = $('#barcode-scanner-help');
        var $barcodeScannerStatus = $('#barcode-scanner-status');
        var $signatureToggle = $('#signature-toggle');
        var $signatureClear = $('#signature-clear');
        var $signatureSave = $('#signature-save');
        var $signaturePadContainer = $('#signature-pad-container');
        var $signatureCanvas = $('#signature-pad');
        var signatureCanvas = $signatureCanvas.length ? $signatureCanvas[0] : null;
        var signatureContext = signatureCanvas && signatureCanvas.getContext ? signatureCanvas.getContext('2d') : null;
        var signatureDrawing = false;
        var signatureHasStroke = false;
        var annotationModalInstance = null;
        var annotationDrawing = false;
        var annotationHasImage = false;
        var annotationHasChanges = false;
        var annotationSourceIndex = -1;
        var annotationActiveImage = null;
        var annotationImageObjectUrl = null;
        var annotationStrokeColor = '#ff0000';
        var barcodeModalInstance = null;
        var barcodeDetectorSupported = typeof window.BarcodeDetector === 'function';
        var barcodeDetector = null;
        var barcodeStream = null;
        var barcodeAnimationFrame = 0;
        var barcodeScanning = false;
        var barcodeErrorNotified = false;
        var mobileModeEnabled = false;
        var mobileModeStorageKey = 'app.mobileMode';
        var mobileFeedbackTimeoutId = null;
        var mobileToggleLabel = ($mobileToggle.attr('data-mobile-mode-label') || '').trim();
        var mobileToggleOnText = ($mobileToggle.attr('data-mobile-mode-on-text') || '').trim() || translate('dashboard.mobile_mode.toggle_on', currentLanguage === 'en' ? 'Enable mobile mode' : 'Activar modo móvil');
        var mobileToggleOffText = ($mobileToggle.attr('data-mobile-mode-off-text') || '').trim() || translate('dashboard.mobile_mode.toggle_off', currentLanguage === 'en' ? 'Disable mobile mode' : 'Desactivar modo móvil');
        var mobileBadgeActiveText = translate('dashboard.mobile_mode.badge_active', currentLanguage === 'en' ? 'Mobile mode active' : 'Modo móvil activo');
        var mobileBadgeInactiveText = translate('dashboard.mobile_mode.badge_inactive', currentLanguage === 'en' ? 'Mobile mode inactive' : 'Modo móvil desactivado');
        var mobilePhotoSuccessText = translate('dashboard.mobile_mode.photo_added', currentLanguage === 'en' ? 'Photo added to attachments.' : 'La foto se agregó a los adjuntos.');
        var mobileSignatureSuccessText = translate('dashboard.mobile_mode.signature_saved', currentLanguage === 'en' ? 'Signature added to attachments.' : 'La firma se agregó a los adjuntos.');
        var mobileSignatureEmptyText = translate('dashboard.mobile_mode.signature_empty', currentLanguage === 'en' ? 'Draw your signature before saving it.' : 'Dibuja tu firma antes de guardarla.');
        var mobileAnnotationSuccessText = translate('dashboard.mobile_mode.annotation_saved', currentLanguage === 'en' ? 'Annotated photo added to attachments.' : 'La foto anotada se agregó a los adjuntos.');
        var mobileAnnotationEmptyText = translate('dashboard.mobile_mode.annotation_empty', currentLanguage === 'en' ? 'Draw on the image before saving your annotations.' : 'Dibuja sobre la imagen antes de guardar las anotaciones.');
        var mobileBarcodeUnsupportedText = translate('dashboard.mobile_mode.barcode_unsupported', currentLanguage === 'en' ? 'Your browser does not support barcode scanning.' : 'Tu navegador no admite el escaneo de códigos de barras.');
        var mobileBarcodeScanningText = translate('dashboard.mobile_mode.barcode_scanning', currentLanguage === 'en' ? 'Align the code within the frame to capture it.' : 'Alinea el código dentro del recuadro para capturarlo.');
        var mobileBarcodeSuccessText = translate('dashboard.mobile_mode.barcode_success', currentLanguage === 'en' ? 'The reference number was updated with the scanned code.' : 'El folio se actualizó con el código escaneado.');
        var mobileBarcodeErrorText = translate('dashboard.mobile_mode.barcode_error', currentLanguage === 'en' ? 'The barcode could not be read. Try again.' : 'No fue posible leer el código de barras. Inténtalo nuevamente.');
        var genericErrorMessage = translate('desembarques.alert.error_generic', currentLanguage === 'en' ? 'An unexpected error occurred while processing the request.' : 'Ocurrió un error inesperado al procesar la solicitud.');
        var statusDefaultValue = '';
        if ($statusSelect.length) {
            statusDefaultValue = $statusSelect.attr('data-default-value') || '';
        }
        var lastAutoGeneratedReference = '';
        var pendingReferenceRequest = null;
        var dynamicFieldGroups = [];
        var $folioAvisoInput = $('#folio_aviso');

        if (offlineQueueEnabled) {
            try {
                offlineQueue.init({
                    endpoint: offlineQueueEndpoint
                });
            } catch (initializationError) {
                console.error('Failed to initialize offline queue:', initializationError);
                offlineQueueEnabled = false;
            }
        }

        function queueSubmissionOffline(formData) {
            if (!offlineQueueEnabled || !offlineQueue || typeof offlineQueue.enqueueFormData !== 'function') {
                return Promise.reject(new Error('Offline queue is not available'));
            }

            return offlineQueue.enqueueFormData(formData, { endpoint: offlineQueueEndpoint });
        }

        function showOfflineQueueSavedAlert() {
            var titleFallback = currentLanguage === 'en'
                ? 'Offline record'
                : 'Registro sin conexión';
            var messageFallback = currentLanguage === 'en'
                ? 'The unloading record was saved on this device and will be sent automatically once you are back online.'
                : 'El desembarque se guardó en el dispositivo y se enviará automáticamente cuando vuelva la conexión.';

            Swal.fire({
                icon: 'info',
                title: translate('desembarques.offline.queue_saved_title', titleFallback),
                text: translate('desembarques.offline.queue_saved_message', messageFallback)
            });
        }

        function handleOfflineQueueFailure(error) {
            console.error('Failed to save submission to the offline queue:', error);
            Swal.fire({
                icon: 'error',
                title: translate('common.error_title', currentLanguage === 'en' ? 'Error' : 'Error'),
                text: translate('desembarques.alert.error_generic', currentLanguage === 'en'
                    ? 'An unexpected error occurred while processing the request.'
                    : 'Ocurrió un error inesperado al procesar la solicitud.')
            });
        }

        if (offlineQueueEnabled) {
            window.addEventListener('offlineQueue:syncSuccess', function (event) {
                var detail = event && event.detail ? event.detail : {};
                var count = typeof detail.sent === 'number' ? detail.sent : 0;

                if (count <= 0) {
                    return;
                }

                var titleFallback = translate('desembarques.alert.success_title', currentLanguage === 'en'
                    ? 'Record saved'
                    : 'Registro guardado');
                var messageFallback = currentLanguage === 'en'
                    ? '{{count}} pending records were synced successfully.'
                    : 'Se enviaron {{count}} registros pendientes.';

                Swal.fire({
                    icon: 'success',
                    title: titleFallback,
                    text: translate('desembarques.offline.sync_success', messageFallback, {
                        count: count
                    })
                });
            });

            window.addEventListener('offlineQueue:syncError', function (event) {
                var detail = event && event.detail ? event.detail : {};
                if (!detail || !detail.errors || detail.errors.length === 0) {
                    return;
                }

                var titleFallback = translate('common.warning_title', currentLanguage === 'en'
                    ? 'Warning'
                    : 'Atención');
                var messageFallback = currentLanguage === 'en'
                    ? 'Some pending records could not be synced. Check your connection and try again.'
                    : 'No se pudieron enviar algunos registros pendientes. Verifica tu conexión e inténtalo de nuevo.';

                Swal.fire({
                    icon: 'warning',
                    title: titleFallback,
                    text: translate('desembarques.offline.sync_error', messageFallback)
                });
            });

            window.addEventListener('offlineQueue:sessionExpired', function () {
                var titleFallback = translate('common.warning_title', currentLanguage === 'en'
                    ? 'Warning'
                    : 'Atención');
                var messageFallback = currentLanguage === 'en'
                    ? 'Pending records could not be synced because the session expired. Please sign in again to complete them.'
                    : 'La sincronización de registros pendientes falló porque la sesión expiró. Inicia sesión nuevamente para completar el envío.';

                Swal.fire({
                    icon: 'warning',
                    title: titleFallback,
                    text: translate('desembarques.offline.session_expired', messageFallback)
                });

                if (!offlineSessionRedirectScheduled) {
                    offlineSessionRedirectScheduled = true;
                    window.setTimeout(function () {
                        window.location.href = 'login.php?status=expired&lang=' + encodeURIComponent(currentLanguage);
                    }, 1500);
                }
            });
        }

        function toLowerText(value) {
            if (typeof value !== 'string') {
                return '';
            }

            var trimmed = value.trim();
            if (!trimmed) {
                return '';
            }

            if (typeof trimmed.toLocaleLowerCase === 'function') {
                return trimmed.toLocaleLowerCase();
            }

            return trimmed.toLowerCase();
        }

        function initDynamicFieldGroup($group) {
            if (!($group && $group.length)) {
                return;
            }

            var targetId = $group.attr('id') || '';
            var fieldName = ($group.data('fieldName') || '').trim();

            if (targetId === '' || fieldName === '') {
                return;
            }

            var $addButton = $('[data-field-target="' + targetId + '"]');
            if (! $addButton.length) {
                return;
            }

            var addDisabledAttr = $addButton.attr('data-dynamic-add-disabled');
            var disableAutoAdd = false;

            if (typeof addDisabledAttr === 'string') {
                var normalizedAttr = addDisabledAttr.trim().toLowerCase();
                disableAutoAdd = normalizedAttr !== ''
                    && normalizedAttr !== 'false'
                    && normalizedAttr !== '0'
                    && normalizedAttr !== 'no';
            }

            var placeholder = typeof $group.data('placeholder') === 'string'
                ? $group.data('placeholder')
                : '';
            var label = typeof $group.data('label') === 'string'
                ? $group.data('label')
                : '';
            var labelId = typeof $group.data('labelId') === 'string'
                ? $group.data('labelId')
                : '';
            var removeAria = typeof $group.data('removeAria') === 'string'
                ? $group.data('removeAria')
                : '';
            var removeTextData = $group.data('removeText');
            var removeDefault = translate('dashboard.form.remove_field', currentLanguage === 'en' ? 'Remove' : 'Eliminar');
            var removeText = typeof removeTextData === 'string' && removeTextData.trim() !== ''
                ? removeTextData.trim()
                : removeDefault;
            if (removeText === '') {
                removeText = removeDefault;
            }
            var maxLengthRaw = parseInt($group.data('maxLength'), 10);
            var maxLength = !Number.isNaN(maxLengthRaw) && maxLengthRaw > 0 ? maxLengthRaw : 100;
            var allowInitialEmptyAttr = $group.data('allowInitialEmpty');
            var allowInitialEmpty = true;

            if (typeof allowInitialEmptyAttr === 'string') {
                allowInitialEmpty = allowInitialEmptyAttr !== 'false';
            } else if (typeof allowInitialEmptyAttr === 'boolean') {
                allowInitialEmpty = allowInitialEmptyAttr;
            }

            function buildRemoveAria() {
                if (removeAria) {
                    return removeAria;
                }

                if (removeText && label) {
                    return removeText + ' ' + toLowerText(label);
                }

                return removeText;
            }

            function applyInputAccessibility($input) {
                if (!($input && $input.length)) {
                    return;
                }

                if (labelId) {
                    $input.attr('aria-labelledby', labelId);
                    $input.removeAttr('aria-label');
                } else if (label) {
                    $input.attr('aria-label', label);
                    $input.removeAttr('aria-labelledby');
                } else {
                    $input.removeAttr('aria-label');
                    $input.removeAttr('aria-labelledby');
                }
            }

            function createField(index) {
                var $wrapper = $('<div></div>')
                    .addClass('input-group dynamic-field mb-2')
                    .attr('data-index', index);
                var inputId = fieldName + '-' + index;
                var $input = $('<input type="text" class="form-control">')
                    .attr({
                        id: inputId,
                        name: fieldName + '[]',
                        maxlength: maxLength,
                        placeholder: placeholder
                    });

                applyInputAccessibility($input);

                var $removeButton = $('<button type="button" class="btn btn-outline-danger dynamic-field-remove"></button>')
                    .text(removeText)
                    .attr('aria-label', buildRemoveAria());

                $wrapper.append($input).append($removeButton);

                return $wrapper;
            }

            function getNextIndex() {
                var nextIndex = Number($group.data('nextIndex'));
                if (!Number.isInteger(nextIndex) || nextIndex < 0) {
                    nextIndex = $group.find('.dynamic-field').length;
                }

                return nextIndex;
            }

            function appendFieldWithValue(value) {
                var nextIndex = getNextIndex();
                var $newField = createField(nextIndex);
                $group.append($newField);
                $group.data('nextIndex', nextIndex + 1);
                updateRemoveButtons();

                if (typeof value !== 'undefined') {
                    var $input = $newField.find('input').first();
                    if ($input.length) {
                        $input.val(value);
                    }
                }

                return $newField;
            }

            function updateRemoveButtons() {
                var $fields = $group.find('.dynamic-field');
                if ($fields.length <= 1) {
                    $fields.each(function () {
                        $(this)
                            .find('.dynamic-field-remove')
                            .attr('disabled', true)
                            .addClass('disabled');
                    });
                } else {
                    $fields.each(function () {
                        $(this)
                            .find('.dynamic-field-remove')
                            .attr('disabled', false)
                            .removeClass('disabled');
                    });
                }
            }

            function resetGroup() {
                if (allowInitialEmpty) {
                    var $fields = $group.find('.dynamic-field');

                    if ($fields.length === 0) {
                        appendFieldWithValue('');
                    } else {
                        $fields.each(function (index) {
                            var $field = $(this);
                            var $input = $field.find('input').first();

                            if (index === 0) {
                                if ($input.length) {
                                    $input.val('');
                                }
                            } else {
                                $field.remove();
                            }
                        });
                    }

                    var remaining = $group.find('.dynamic-field').length;
                    $group.data('nextIndex', remaining > 0 ? remaining : 1);
                } else {
                    $group.find('.dynamic-field').remove();
                    $group.data('nextIndex', 0);
                }

                updateRemoveButtons();
            }

            function setFieldValues(values) {
                var normalized = Array.isArray(values) ? values : [];

                if (normalized.length === 0) {
                    resetGroup();
                    return;
                }

                normalized.forEach(function (value, index) {
                    var stringValue = value === undefined || value === null ? '' : String(value);
                    var $field = $group.find('.dynamic-field').eq(index);

                    if (! $field.length) {
                        appendFieldWithValue(stringValue);
                        return;
                    }

                    var $input = $field.find('input').first();
                    if ($input.length) {
                        $input.val(stringValue);
                    }
                });

                var $fields = $group.find('.dynamic-field');
                if ($fields.length > normalized.length) {
                    $fields.slice(normalized.length).remove();
                }

                $group.data('nextIndex', normalized.length);
                updateRemoveButtons();
            }

            function normalizeExistingFields() {
                var nextIndex = 0;

                $group.find('.dynamic-field').each(function (index) {
                    var $wrapper = $(this);
                    var $input = $wrapper.find('input').first();

                    if (! $input.length) {
                        $input = $('<input type="text" class="form-control">');
                        $wrapper.prepend($input);
                    }

                    var inputId = fieldName + '-' + index;
                    $wrapper.attr('data-index', index);

                    $input.attr({
                        id: inputId,
                        name: fieldName + '[]',
                        maxlength: maxLength,
                        placeholder: placeholder
                    });

                    applyInputAccessibility($input);

                    var $removeButton = $wrapper.find('.dynamic-field-remove').first();
                    if (! $removeButton.length) {
                        $removeButton = $('<button type="button" class="btn btn-outline-danger dynamic-field-remove"></button>');
                        $wrapper.append($removeButton);
                    }

                    $removeButton.text(removeText);
                    $removeButton.attr('aria-label', buildRemoveAria());

                    nextIndex = index + 1;
                });

                if (nextIndex === 0 && allowInitialEmpty) {
                    var $initialField = createField(0);
                    $group.append($initialField);
                    nextIndex = 1;
                }

                $group.data('nextIndex', nextIndex);
                updateRemoveButtons();
            }

            normalizeExistingFields();

            if (! disableAutoAdd) {
                $addButton.on('click', function () {
                    var nextIndex = getNextIndex();
                    var $newField = createField(nextIndex);
                    $group.append($newField);
                    $group.data('nextIndex', nextIndex + 1);
                    updateRemoveButtons();

                    window.setTimeout(function () {
                        var $input = $newField.find('input').first();
                        if ($input.length) {
                            $input.trigger('focus');
                        }
                    }, 0);
                });
            }

            $group.on('click', '.dynamic-field-remove', function () {
                var $button = $(this);
                if ($button.is(':disabled')) {
                    return;
                }

                var $fields = $group.find('.dynamic-field');
                if ($fields.length <= 1) {
                    return;
                }

                $button.closest('.dynamic-field').remove();
                updateRemoveButtons();
            });

            dynamicFieldGroups.push({
                reset: resetGroup,
                setValues: setFieldValues
            });

            $group.data('dynamicFieldApi', {
                addValue: function (value) {
                    var stringValue = value === undefined || value === null ? '' : String(value);
                    return appendFieldWithValue(stringValue);
                },
                setValues: setFieldValues,
                reset: resetGroup,
                clear: resetGroup
            });
        }

        $('.dynamic-field-group').each(function () {
            initDynamicFieldGroup($(this));
        });

        function readStoredMobilePreference() {
            try {
                var stored = window.localStorage.getItem(mobileModeStorageKey);
                if (stored === '1') {
                    return true;
                }
                if (stored === '0') {
                    return false;
                }
            } catch (error) {
                // Ignore storage access issues
            }

            return null;
        }

        function storeMobilePreference(enabled) {
            try {
                window.localStorage.setItem(mobileModeStorageKey, enabled ? '1' : '0');
            } catch (error) {
                // Ignore storage write issues
            }
        }

        function determineInitialMobileMode() {
            var storedPreference = readStoredMobilePreference();
            if (storedPreference !== null) {
                return storedPreference;
            }

            if (window.matchMedia) {
                var coarsePointer = window.matchMedia('(pointer: coarse)');
                if (coarsePointer && coarsePointer.matches) {
                    return true;
                }

                var smallViewport = window.matchMedia('(max-width: 768px)');
                if (smallViewport && smallViewport.matches) {
                    return true;
                }
            }

            return false;
        }

        function updateMobileToggleState(enabled) {
            if (! $mobileToggle.length) {
                return;
            }

            var text = enabled ? mobileToggleOffText : mobileToggleOnText;
            var ariaLabel = mobileToggleLabel ? mobileToggleLabel + ': ' + text : text;

            $mobileToggle.attr('aria-pressed', enabled ? 'true' : 'false');
            $mobileToggle.attr('title', ariaLabel);
            $mobileToggle.attr('aria-label', ariaLabel);
            $mobileToggle.find('.mobile-mode-toggle-text').text(text);
        }

        function hideMobileFeedback() {
            window.clearTimeout(mobileFeedbackTimeoutId);
            mobileFeedbackTimeoutId = null;
            if ($mobileModeFeedback.length) {
                $mobileModeFeedback.addClass('d-none').removeClass('alert-success alert-danger').text('');
            }
        }

        function notifyMobileFeedback(message, type) {
            if (! $mobileModeFeedback.length || !message) {
                return;
            }

            window.clearTimeout(mobileFeedbackTimeoutId);
            $mobileModeFeedback
                .removeClass('d-none alert-success alert-danger')
                .addClass(type === 'error' ? 'alert-danger' : 'alert-success')
                .text(message);

            mobileFeedbackTimeoutId = window.setTimeout(function () {
                hideMobileFeedback();
            }, 4000);
        }

        function createModalInstance($element) {
            if (!$element || !$element.length) {
                return null;
            }

            if (window.bootstrap && typeof window.bootstrap.Modal === 'function') {
                return new window.bootstrap.Modal($element[0]);
            }

            if (typeof $element.modal === 'function') {
                return {
                    show: function () {
                        $element.modal('show');
                    },
                    hide: function () {
                        $element.modal('hide');
                    }
                };
            }

            return null;
        }

        annotationModalInstance = createModalInstance($mobileAnnotationModal);
        barcodeModalInstance = createModalInstance($barcodeModal);

        if (barcodeDetectorSupported) {
            try {
                barcodeDetector = new window.BarcodeDetector({
                    formats: ['code_128', 'code_39', 'code_93', 'ean_13', 'ean_8', 'qr_code', 'upc_a', 'upc_e']
                });
            } catch (initializationError) {
                console.warn('BarcodeDetector initialization failed:', initializationError);
                barcodeDetector = null;
                barcodeDetectorSupported = false;
            }
        }

        function updateSignatureButtons() {
            var disabled = !signatureHasStroke;
            if ($signatureClear.length) {
                $signatureClear.prop('disabled', disabled);
            }
            if ($signatureSave.length) {
                $signatureSave.prop('disabled', disabled);
            }
        }

        function clearSignatureCanvas() {
            if (!signatureCanvas || !signatureContext) {
                return;
            }

            signatureContext.clearRect(0, 0, signatureCanvas.width, signatureCanvas.height);
            signatureHasStroke = false;
            updateSignatureButtons();
        }

        function hideSignaturePad() {
            if ($signaturePadContainer.length) {
                $signaturePadContainer.addClass('d-none');
            }
            clearSignatureCanvas();
        }

        function getSignatureStrokeColor() {
            var referenceElement = document.querySelector('.form-card .card-body') || document.body;
            var computed = window.getComputedStyle(referenceElement);
            if (computed && computed.color) {
                return computed.color;
            }

            return '#000000';
        }

        function resizeSignatureCanvas(force) {
            if (!signatureCanvas || !signatureContext) {
                return;
            }

            if ($signaturePadContainer.length && $signaturePadContainer.hasClass('d-none') && !force) {
                return;
            }

            if (signatureHasStroke && !force) {
                return;
            }

            var containerElement = $signaturePadContainer.length ? $signaturePadContainer[0] : signatureCanvas.parentNode;
            if (!containerElement) {
                return;
            }

            var containerWidth = containerElement.clientWidth;
            if (!containerWidth) {
                containerWidth = containerElement.getBoundingClientRect().width;
            }

            if (!containerWidth) {
                containerWidth = 600;
            }

            var desiredHeight = Math.max(160, Math.round(containerWidth * 0.4));
            var ratio = window.devicePixelRatio || 1;

            signatureCanvas.width = containerWidth * ratio;
            signatureCanvas.height = desiredHeight * ratio;
            signatureCanvas.style.width = containerWidth + 'px';
            signatureCanvas.style.height = desiredHeight + 'px';

            signatureContext.setTransform(1, 0, 0, 1, 0, 0);
            signatureContext.scale(ratio, ratio);
            signatureContext.lineWidth = 3;
            signatureContext.lineCap = 'round';
            signatureContext.lineJoin = 'round';
            signatureContext.strokeStyle = getSignatureStrokeColor();

            if (!signatureHasStroke) {
                signatureContext.clearRect(0, 0, containerWidth, desiredHeight);
            }
        }

        function setMobileMode(enabled, isInitial) {
            mobileModeEnabled = Boolean(enabled);

            $body.toggleClass('mobile-mode', mobileModeEnabled);
            updateMobileToggleState(mobileModeEnabled);

            if ($mobileModeBanner.length) {
                $mobileModeBanner.toggleClass('d-none', !mobileModeEnabled);
            }

            if ($mobileModeBadge.length) {
                $mobileModeBadge.text(mobileModeEnabled ? mobileBadgeActiveText : mobileBadgeInactiveText);
                $mobileModeBadge.toggleClass('bg-success', mobileModeEnabled);
                $mobileModeBadge.toggleClass('bg-secondary', !mobileModeEnabled);
            }

            if ($mobileEvidenceTools.length) {
                $mobileEvidenceTools.toggleClass('d-none', !mobileModeEnabled);
            }

            if ($mobileModeHint.length) {
                $mobileModeHint.toggleClass('d-none', !mobileModeEnabled);
            }

            if ($mobilePhotoHelp.length) {
                $mobilePhotoHelp.toggleClass('d-none', !mobileModeEnabled);
            }

            if ($mobileAnnotationHelp.length) {
                $mobileAnnotationHelp.toggleClass('d-none', !mobileModeEnabled);
            }

            if ($mobileBarcodeHelp.length) {
                $mobileBarcodeHelp.toggleClass('d-none', !mobileModeEnabled);
            }

            if ($attachmentsInput.length) {
                if (mobileModeEnabled) {
                    $attachmentsInput.attr('capture', 'environment');
                } else {
                    $attachmentsInput.removeAttr('capture');
                }
            }

            if (!mobileModeEnabled) {
                hideSignaturePad();
                hideMobileFeedback();
                if (annotationModalInstance && typeof annotationModalInstance.hide === 'function') {
                    annotationModalInstance.hide();
                }
                if (barcodeModalInstance && typeof barcodeModalInstance.hide === 'function') {
                    barcodeModalInstance.hide();
                }
                stopBarcodeScanning();
            } else {
                window.setTimeout(function () {
                    resizeSignatureCanvas(true);
                }, 50);
            }

            if (!isInitial) {
                storeMobilePreference(mobileModeEnabled);
            }
        }

        function getSignatureCoordinates(event) {
            if (!signatureCanvas) {
                return { x: 0, y: 0 };
            }

            var rect = signatureCanvas.getBoundingClientRect();
            return {
                x: event.clientX - rect.left,
                y: event.clientY - rect.top
            };
        }

        function startSignatureDrawing(event) {
            if (!signatureCanvas || !signatureContext || !mobileModeEnabled) {
                return;
            }

            event.preventDefault();
            signatureDrawing = true;
            signatureContext.beginPath();
            signatureContext.strokeStyle = getSignatureStrokeColor();

            var startPoint = getSignatureCoordinates(event);
            signatureContext.moveTo(startPoint.x, startPoint.y);
            signatureContext.lineTo(startPoint.x + 0.1, startPoint.y + 0.1);
            signatureContext.stroke();
            signatureHasStroke = true;
            updateSignatureButtons();

            if (typeof signatureCanvas.setPointerCapture === 'function') {
                try {
                    signatureCanvas.setPointerCapture(event.pointerId);
                } catch (error) {
                    // Ignore pointer capture errors
                }
            }
        }

        function continueSignatureDrawing(event) {
            if (!signatureDrawing || !signatureContext) {
                return;
            }

            event.preventDefault();
            var point = getSignatureCoordinates(event);
            signatureContext.lineTo(point.x, point.y);
            signatureContext.stroke();
        }

        function finishSignatureDrawing(event) {
            if (!signatureDrawing || !signatureContext) {
                return;
            }

            signatureDrawing = false;
            signatureContext.closePath();

            if (event && typeof signatureCanvas.releasePointerCapture === 'function') {
                try {
                    signatureCanvas.releasePointerCapture(event.pointerId);
                } catch (error) {
                    // Ignore pointer release errors
                }
            }

            updateSignatureButtons();
        }

        function dataUrlToFile(dataUrl, filename) {
            var parts = String(dataUrl || '').split(',');
            if (parts.length < 2) {
                return null;
            }

            var mimeMatch = parts[0].match(/:(.*?);/);
            var mimeType = mimeMatch && mimeMatch[1] ? mimeMatch[1] : 'image/png';
            var binary = atob(parts[1]);
            var length = binary.length;
            var buffer = new Uint8Array(length);

            for (var i = 0; i < length; i += 1) {
                buffer[i] = binary.charCodeAt(i);
            }

            try {
                return new File([buffer], filename, { type: mimeType });
            } catch (error) {
                return null;
            }
        }

        function attachSignatureFile(file) {
            if (!file) {
                notifyMobileFeedback(genericErrorMessage, 'error');
                return;
            }

            clearAttachmentError();
            var result = addAttachments([file]);
            updateAttachmentState();

            if (result.error) {
                showAttachmentError(result.error);
                notifyMobileFeedback(result.error, 'error');
                return;
            }

            if (result.added > 0) {
                notifyMobileFeedback(mobileSignatureSuccessText, 'success');
                clearSignatureCanvas();
            }
        }

        function saveSignatureAsAttachment() {
            if (!signatureCanvas || !signatureHasStroke) {
                notifyMobileFeedback(mobileSignatureEmptyText, 'error');
                return;
            }

            var timestamp = new Date().toISOString().replace(/[:.]/g, '-');
            var filename = 'firma-' + timestamp + '.png';

            if (typeof signatureCanvas.toBlob === 'function') {
                signatureCanvas.toBlob(function (blob) {
                    if (!blob) {
                        notifyMobileFeedback(genericErrorMessage, 'error');
                        return;
                    }

                    var file = blob instanceof File ? blob : new File([blob], filename, { type: blob.type || 'image/png' });
                    attachSignatureFile(file);
                }, 'image/png');
                return;
            }

            var fileFromDataUrl = dataUrlToFile(signatureCanvas.toDataURL('image/png'), filename);
            if (!fileFromDataUrl) {
                notifyMobileFeedback(genericErrorMessage, 'error');
                return;
            }

            attachSignatureFile(fileFromDataUrl);
        }

        function getAnnotationStrokeColor() {
            if ($annotationColorInput.length) {
                var value = $annotationColorInput.val();
                if (value) {
                    return String(value);
                }
            }

            return annotationStrokeColor || '#ff0000';
        }

        function revokeAnnotationImageUrl() {
            if (annotationImageObjectUrl && window.URL && typeof window.URL.revokeObjectURL === 'function') {
                try {
                    window.URL.revokeObjectURL(annotationImageObjectUrl);
                } catch (error) {
                    // Ignore revoke errors
                }
            }

            annotationImageObjectUrl = null;
        }

        function renderAnnotationBase() {
            if (!annotationCanvasElement || !annotationContext || !annotationActiveImage) {
                return;
            }

            var containerElement = annotationCanvasElement.parentNode;
            var availableWidth = containerElement && containerElement.clientWidth ? containerElement.clientWidth : annotationActiveImage.width;
            if (!availableWidth || !Number.isFinite(availableWidth)) {
                availableWidth = annotationActiveImage.width;
            }

            var maxHeight = window.innerHeight ? Math.floor(window.innerHeight * 0.6) : annotationActiveImage.height;
            if (!maxHeight || !Number.isFinite(maxHeight) || maxHeight <= 0) {
                maxHeight = annotationActiveImage.height;
            }

            var scale = Math.min(availableWidth / annotationActiveImage.width, maxHeight / annotationActiveImage.height);
            if (!Number.isFinite(scale) || scale <= 0) {
                scale = 1;
            }

            var displayWidth = Math.max(1, Math.round(annotationActiveImage.width * scale));
            var displayHeight = Math.max(1, Math.round(annotationActiveImage.height * scale));
            var deviceRatio = window.devicePixelRatio || 1;

            annotationCanvasElement.width = displayWidth * deviceRatio;
            annotationCanvasElement.height = displayHeight * deviceRatio;
            annotationCanvasElement.style.width = displayWidth + 'px';
            annotationCanvasElement.style.height = displayHeight + 'px';

            annotationContext.setTransform(1, 0, 0, 1, 0, 0);
            annotationContext.clearRect(0, 0, annotationCanvasElement.width, annotationCanvasElement.height);
            annotationContext.scale(deviceRatio, deviceRatio);
            annotationContext.drawImage(annotationActiveImage, 0, 0, displayWidth, displayHeight);
            annotationContext.lineCap = 'round';
            annotationContext.lineJoin = 'round';
            annotationContext.lineWidth = 4;
            annotationContext.strokeStyle = getAnnotationStrokeColor();

            if ($annotationCanvasWrapper.length) {
                $annotationCanvasWrapper.removeClass('d-none');
            }
        }

        function updateAnnotationControls() {
            var hasImage = annotationHasImage;
            var canSave = hasImage && annotationHasChanges;

            if ($annotationClear.length) {
                $annotationClear.prop('disabled', !hasImage);
            }

            if ($annotationSave.length) {
                $annotationSave.prop('disabled', !canSave);
            }

            if ($annotationColorInput.length) {
                $annotationColorInput.prop('disabled', !hasImage);
            }

            if ($annotationDrawHint.length) {
                $annotationDrawHint.toggleClass('d-none', !hasImage);
            }
        }

        function loadAnnotationSource(index) {
            if (!Number.isFinite(index)) {
                index = -1;
            }

            revokeAnnotationImageUrl();
            annotationSourceIndex = index;
            annotationActiveImage = null;
            annotationHasImage = false;
            annotationHasChanges = false;

            if ($annotationCanvasWrapper.length) {
                $annotationCanvasWrapper.addClass('d-none');
            }

            var file = index >= 0 && index < attachments.length ? attachments[index] : null;
            if (!file || !isImageFile(file) || !annotationCanvasElement || !annotationContext) {
                updateAnnotationControls();
                return;
            }

            annotationHasImage = true;
            annotationStrokeColor = getAnnotationStrokeColor();
            updateAnnotationControls();

            var image = new Image();
            image.onload = function () {
                annotationActiveImage = image;
                annotationHasImage = true;
                annotationHasChanges = false;
                renderAnnotationBase();
                updateAnnotationControls();
                revokeAnnotationImageUrl();
            };
            image.onerror = function () {
                annotationActiveImage = null;
                annotationHasImage = false;
                annotationHasChanges = false;
                revokeAnnotationImageUrl();
                updateAnnotationControls();
                notifyMobileFeedback(genericErrorMessage, 'error');
            };

            if (window.URL && typeof window.URL.createObjectURL === 'function') {
                annotationImageObjectUrl = window.URL.createObjectURL(file);
                image.src = annotationImageObjectUrl;
            } else {
                var reader = new FileReader();
                reader.onload = function (event) {
                    annotationImageObjectUrl = null;
                    image.src = event && event.target ? event.target.result : '';
                };
                reader.onerror = function () {
                    annotationActiveImage = null;
                    annotationHasImage = false;
                    annotationHasChanges = false;
                    updateAnnotationControls();
                };
                reader.readAsDataURL(file);
            }
        }

        function refreshAnnotationOptions() {
            if (!$annotationImageSelect.length) {
                return;
            }

            var imageAttachments = attachments
                .map(function (file, index) {
                    return { file: file, index: index };
                })
                .filter(function (entry) {
                    return isImageFile(entry.file);
                });

            if (imageAttachments.length === 0) {
                $annotationImageSelect.empty().prop('disabled', true);
                if ($annotationEmptyState.length) {
                    $annotationEmptyState.removeClass('d-none');
                }
                annotationSourceIndex = -1;
                annotationActiveImage = null;
                annotationHasImage = false;
                annotationHasChanges = false;
                if ($annotationCanvasWrapper.length) {
                    $annotationCanvasWrapper.addClass('d-none');
                }
                updateAnnotationControls();
                return;
            }

            var selectedIndex = annotationSourceIndex;
            if (!imageAttachments.some(function (entry) { return entry.index === selectedIndex; })) {
                selectedIndex = imageAttachments[0].index;
            }

            $annotationImageSelect.empty().prop('disabled', false);
            imageAttachments.forEach(function (entry) {
                var label = entry.file && entry.file.name ? entry.file.name : (currentLanguage === 'en' ? 'Image attachment' : 'Imagen adjunta');
                var $option = $('<option></option>')
                    .attr('value', String(entry.index))
                    .text(label);

                if (entry.index === selectedIndex) {
                    $option.prop('selected', true);
                }

                $annotationImageSelect.append($option);
            });

            if ($annotationEmptyState.length) {
                $annotationEmptyState.addClass('d-none');
            }

            loadAnnotationSource(selectedIndex);
        }

        function getAnnotationCoordinates(event) {
            if (!annotationCanvasElement) {
                return { x: 0, y: 0 };
            }

            var rect = annotationCanvasElement.getBoundingClientRect();
            return {
                x: event.clientX - rect.left,
                y: event.clientY - rect.top
            };
        }

        function startAnnotationDrawing(event) {
            if (!annotationCanvasElement || !annotationContext || !annotationHasImage || !mobileModeEnabled) {
                return;
            }

            event.preventDefault();
            annotationDrawing = true;
            annotationContext.beginPath();
            annotationStrokeColor = getAnnotationStrokeColor();
            annotationContext.strokeStyle = annotationStrokeColor;
            annotationContext.lineWidth = 4;

            var startPoint = getAnnotationCoordinates(event);
            annotationContext.moveTo(startPoint.x, startPoint.y);
            annotationContext.lineTo(startPoint.x + 0.1, startPoint.y + 0.1);
            annotationContext.stroke();
            annotationHasChanges = true;
            updateAnnotationControls();

            if (typeof annotationCanvasElement.setPointerCapture === 'function') {
                try {
                    annotationCanvasElement.setPointerCapture(event.pointerId);
                } catch (error) {
                    // Ignore pointer capture errors
                }
            }
        }

        function continueAnnotationDrawing(event) {
            if (!annotationDrawing || !annotationContext) {
                return;
            }

            event.preventDefault();
            var point = getAnnotationCoordinates(event);
            annotationContext.lineTo(point.x, point.y);
            annotationContext.stroke();
        }

        function finishAnnotationDrawing(event) {
            if (!annotationDrawing || !annotationContext) {
                return;
            }

            annotationDrawing = false;
            annotationContext.closePath();

            if (event && typeof annotationCanvasElement.releasePointerCapture === 'function') {
                try {
                    annotationCanvasElement.releasePointerCapture(event.pointerId);
                } catch (error) {
                    // Ignore pointer release errors
                }
            }

            updateAnnotationControls();
        }

        function clearAnnotationDrawing() {
            if (!annotationHasImage) {
                return;
            }

            renderAnnotationBase();
            annotationHasChanges = false;
            updateAnnotationControls();
        }

        function saveAnnotationAsAttachment() {
            if (!annotationCanvasElement || !annotationHasImage) {
                notifyMobileFeedback(genericErrorMessage, 'error');
                return;
            }

            if (!annotationHasChanges) {
                notifyMobileFeedback(mobileAnnotationEmptyText, 'error');
                return;
            }

            clearAttachmentError();

            var baseFile = annotationSourceIndex >= 0 ? attachments[annotationSourceIndex] : null;
            var baseName = baseFile && baseFile.name ? baseFile.name : 'photo';
            var baseNameRoot = baseName.replace(/\.[^/.]+$/, '');
            if (!baseNameRoot) {
                baseNameRoot = 'photo';
            }
            var filename = baseNameRoot + '-annotated.png';

            var handleFile = function (file) {
                if (!file) {
                    notifyMobileFeedback(genericErrorMessage, 'error');
                    return;
                }

                var result = addAttachments([file]);
                updateAttachmentState();

                if (result.error) {
                    showAttachmentError(result.error);
                    notifyMobileFeedback(result.error, 'error');
                    return;
                }

                if (result.added > 0) {
                    notifyMobileFeedback(mobileAnnotationSuccessText, 'success');
                    annotationHasChanges = false;
                    if (annotationModalInstance && typeof annotationModalInstance.hide === 'function') {
                        annotationModalInstance.hide();
                    }
                }
            };

            if (typeof annotationCanvasElement.toBlob === 'function') {
                annotationCanvasElement.toBlob(function (blob) {
                    if (!blob) {
                        notifyMobileFeedback(genericErrorMessage, 'error');
                        return;
                    }

                    var file = blob instanceof File ? blob : new File([blob], filename, { type: blob.type || 'image/png' });
                    handleFile(file);
                }, 'image/png');

                return;
            }

            var dataUrl = annotationCanvasElement.toDataURL('image/png');
            var fileFromDataUrl = dataUrlToFile(dataUrl, filename);
            handleFile(fileFromDataUrl);
        }

        function resetBarcodeStatus() {
            if ($barcodeScannerStatus.length) {
                $barcodeScannerStatus
                    .addClass('d-none')
                    .removeClass('alert-danger alert-success')
                    .text('');
            }

            barcodeErrorNotified = false;
        }

        function isBarcodeScanningSupported() {
            return Boolean(barcodeDetectorSupported && barcodeDetector && navigator.mediaDevices && typeof navigator.mediaDevices.getUserMedia === 'function');
        }

        function stopBarcodeScanning() {
            barcodeScanning = false;

            if (barcodeAnimationFrame) {
                window.cancelAnimationFrame(barcodeAnimationFrame);
                barcodeAnimationFrame = 0;
            }

            if (barcodeVideoElement) {
                try {
                    barcodeVideoElement.pause();
                } catch (error) {
                    // Ignore pause errors
                }

                try {
                    if ('srcObject' in barcodeVideoElement) {
                        barcodeVideoElement.srcObject = null;
                    } else {
                        barcodeVideoElement.removeAttribute('srcObject');
                    }
                } catch (error) {
                    // Ignore stream detach errors
                }

                if (typeof barcodeVideoElement.load === 'function') {
                    barcodeVideoElement.load();
                }
            }

            if (barcodeStream && typeof barcodeStream.getTracks === 'function') {
                barcodeStream.getTracks().forEach(function (track) {
                    if (track && typeof track.stop === 'function') {
                        track.stop();
                    }
                });
            }

            barcodeStream = null;

            if ($barcodeScannerContainer.length) {
                $barcodeScannerContainer.addClass('d-none');
            }
        }

        function processBarcodeFrame() {
            if (!barcodeScanning || !barcodeDetector || !barcodeVideoElement) {
                return;
            }

            barcodeDetector.detect(barcodeVideoElement).then(function (codes) {
                if (Array.isArray(codes) && codes.length > 0) {
                    var detected = codes[0] || {};
                    var value = '';

                    if (detected.rawValue && String(detected.rawValue).trim() !== '') {
                        value = String(detected.rawValue).trim();
                    } else if (detected.rawData && String(detected.rawData).trim() !== '') {
                        value = String(detected.rawData).trim();
                    }

                    if (value !== '') {
                        stopBarcodeScanning();

                        if ($folioAvisoInput.length) {
                            $folioAvisoInput.val(value).trigger('change');
                        }

                        if ($barcodeScannerStatus.length) {
                            $barcodeScannerStatus.removeClass('d-none alert-danger').addClass('alert-success').text(mobileBarcodeSuccessText);
                        }

                        notifyMobileFeedback(mobileBarcodeSuccessText, 'success');

                        if (barcodeModalInstance && typeof barcodeModalInstance.hide === 'function') {
                            window.setTimeout(function () {
                                barcodeModalInstance.hide();
                            }, 600);
                        }

                        return;
                    }
                }

                barcodeAnimationFrame = window.requestAnimationFrame(processBarcodeFrame);
            }).catch(function (error) {
                console.error('Barcode detection error:', error);

                if (!barcodeErrorNotified) {
                    barcodeErrorNotified = true;
                    if ($barcodeScannerStatus.length) {
                        $barcodeScannerStatus.removeClass('d-none alert-success').addClass('alert-danger').text(mobileBarcodeErrorText);
                    }
                    notifyMobileFeedback(mobileBarcodeErrorText, 'error');
                }

                barcodeAnimationFrame = window.requestAnimationFrame(processBarcodeFrame);
            });
        }

        function startBarcodeScanning() {
            if (!isBarcodeScanningSupported()) {
                if ($barcodeUnsupportedAlert.length) {
                    $barcodeUnsupportedAlert.removeClass('d-none').text(mobileBarcodeUnsupportedText);
                }

                if ($barcodeScannerContainer.length) {
                    $barcodeScannerContainer.addClass('d-none');
                }

                notifyMobileFeedback(mobileBarcodeUnsupportedText, 'error');
                return;
            }

            if ($barcodeUnsupportedAlert.length) {
                $barcodeUnsupportedAlert.addClass('d-none');
            }

            if ($barcodeScannerContainer.length) {
                $barcodeScannerContainer.removeClass('d-none');
            }

            if ($barcodeScannerHelp.length) {
                $barcodeScannerHelp.text(mobileBarcodeScanningText);
            }

            resetBarcodeStatus();

            navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: { ideal: 'environment' }
                }
            }).then(function (stream) {
                barcodeStream = stream;

                if (barcodeVideoElement) {
                    try {
                        barcodeVideoElement.srcObject = stream;
                    } catch (assignmentError) {
                        if ('srcObject' in barcodeVideoElement) {
                            barcodeVideoElement.srcObject = stream;
                        }
                    }

                    var beginDetection = function () {
                        barcodeVideoElement.removeEventListener('loadeddata', beginDetection);
                        barcodeVideoElement.removeEventListener('loadedmetadata', beginDetection);
                        barcodeScanning = true;
                        barcodeAnimationFrame = window.requestAnimationFrame(processBarcodeFrame);
                    };

                    barcodeVideoElement.addEventListener('loadeddata', beginDetection);
                    barcodeVideoElement.addEventListener('loadedmetadata', beginDetection);

                    var playPromise = barcodeVideoElement.play();
                    if (playPromise && typeof playPromise.then === 'function') {
                        playPromise.then(function () {
                            beginDetection();
                        }).catch(function () {
                            beginDetection();
                        });
                    } else {
                        beginDetection();
                    }
                } else {
                    barcodeScanning = true;
                    barcodeAnimationFrame = window.requestAnimationFrame(processBarcodeFrame);
                }
            }).catch(function (error) {
                console.error('Unable to start barcode scanner:', error);
                if ($barcodeScannerStatus.length) {
                    $barcodeScannerStatus.removeClass('d-none alert-success').addClass('alert-danger').text(mobileBarcodeErrorText);
                }
                notifyMobileFeedback(mobileBarcodeErrorText, 'error');
            });
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

        function isImageFile(file) {
            if (!file) {
                return false;
            }

            if (file.type && typeof file.type === 'string' && file.type.indexOf('image/') === 0) {
                return true;
            }

            var extension = getFileExtension(file.name || '');
            if (!extension) {
                return false;
            }

            var imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif'];
            return imageExtensions.indexOf(extension) !== -1;
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

        function clearAttachmentError() {
            if ($attachmentsFeedback.length) {
                $attachmentsFeedback.text('').addClass('d-none');
            }
        }

        function showAttachmentError(message) {
            if ($attachmentsFeedback.length) {
                $attachmentsFeedback.text(String(message || '')).removeClass('d-none');
            }
        }

        function renderAttachmentsList() {
            if (!$attachmentsList.length) {
                return;
            }

            $attachmentsList.empty();

            if (attachments.length === 0) {
                $attachmentsList.addClass('d-none');
                if ($attachmentsEmpty.length) {
                    $attachmentsEmpty.removeClass('d-none');
                }

                return;
            }

            $attachmentsList.removeClass('d-none');

            if ($attachmentsEmpty.length) {
                $attachmentsEmpty.addClass('d-none');
            }

            attachments.forEach(function (file, index) {
                var fileName = file && file.name ? file.name : (currentLanguage === 'en' ? 'File' : 'Archivo');
                var sizeLabel = '';

                if (file && typeof file.size === 'number' && file.size >= 0) {
                    sizeLabel = formatFileSize(file.size);
                }

                var $item = $('<li></li>').addClass('list-group-item d-flex justify-content-between align-items-center');
                var $info = $('<div></div>').addClass('me-3 flex-grow-1 text-break');
                $info.append($('<div></div>').text(fileName));

                if (sizeLabel) {
                    $info.append($('<div></div>').addClass('small text-muted').text(sizeLabel));
                }

                var removeLabel = translate('dashboard.form.attachments_remove', currentLanguage === 'en' ? 'Remove' : 'Quitar');
                var $removeButton = $('<button type="button"></button>')
                    .addClass('btn btn-outline-danger btn-sm')
                    .attr('data-action', 'remove-attachment')
                    .attr('data-index', String(index))
                    .text(removeLabel);

                $item.append($info).append($removeButton);
                $attachmentsList.append($item);
            });
        }

        function syncAttachmentsInput() {
            if (!dataTransferSupported || !$attachmentsInput.length) {
                return;
            }

            try {
                var dataTransfer = new DataTransfer();
                attachments.forEach(function (file) {
                    if (file) {
                        dataTransfer.items.add(file);
                    }
                });
                $attachmentsInput[0].files = dataTransfer.files;
            } catch (error) {
                // Ignore browsers that do not support programmatic assignment
            }
        }

        function updateAttachmentState() {
            renderAttachmentsList();

            if (dataTransferSupported) {
                syncAttachmentsInput();
            } else if (attachments.length === 0 && $attachmentsInput.length) {
                $attachmentsInput.val('');
            }

            refreshAnnotationOptions();
        }

        function clearAttachments() {
            attachments = [];
            if ($attachmentsInput.length) {
                $attachmentsInput.val('');
            }
            updateAttachmentState();
            clearAttachmentError();
        }

        function addAttachments(files) {
            if (!Array.isArray(files) || files.length === 0) {
                return { added: 0, error: '' };
            }

            var added = 0;
            var errorMessage = '';

            files.forEach(function (file) {
                if (!file) {
                    return;
                }

                if (maxAttachmentsPerRequest > 0 && attachments.length >= maxAttachmentsPerRequest) {
                    if (!errorMessage) {
                        errorMessage = translate('desembarques.files.error_too_many', 'Too many files selected.', {
                            max: maxAttachmentsPerRequest
                        });
                    }
                    return;
                }

                var extension = getFileExtension(file.name || '');

                if (!isExtensionAllowed(extension)) {
                    if (!errorMessage) {
                        errorMessage = translate('dashboard.form.attachments_error_type', 'The selected file type is not allowed.');
                    }
                    return;
                }

                if (maxAttachmentSize > 0 && file.size > maxAttachmentSize) {
                    if (!errorMessage) {
                        errorMessage = translate('dashboard.form.attachments_error_size', 'The file exceeds the maximum allowed size ({max}).', {
                            max: formatFileSize(maxAttachmentSize)
                        });
                    }
                    return;
                }

                attachments.push(file);
                added += 1;
            });

            return { added: added, error: errorMessage };
        }

        function handleAttachmentInputChange(event) {
            var selectedFiles = [];

            if (event && event.target && event.target.files) {
                selectedFiles = Array.prototype.slice.call(event.target.files);
            }

            if (selectedFiles.length === 0) {
                return;
            }

            clearAttachmentError();

            var result = addAttachments(selectedFiles);

            if (dataTransferSupported && $attachmentsInput.length) {
                $attachmentsInput.val('');
            }

            updateAttachmentState();

            if (result.error) {
                showAttachmentError(result.error);
            }
        }

        if ($attachmentsInput.length) {
            $attachmentsInput.on('change', handleAttachmentInputChange);
        }

        if ($attachmentsList.length) {
            $attachmentsList.on('click', '[data-action="remove-attachment"]', function (event) {
                event.preventDefault();

                var indexValue = $(this).attr('data-index') || '';
                var index = Number(indexValue);

                if (!Number.isFinite(index) || index < 0 || index >= attachments.length) {
                    return;
                }

                attachments.splice(index, 1);
                updateAttachmentState();

                if (attachments.length === 0) {
                    clearAttachmentError();
                }
            });
        }

        if ($mobilePhotoButton.length && $mobilePhotoInput.length) {
            $mobilePhotoButton.on('click', function (event) {
                event.preventDefault();

                if (!mobileModeEnabled) {
                    setMobileMode(true, false);
                }

                $mobilePhotoInput.trigger('click');
            });

            $mobilePhotoInput.on('change', function (event) {
                var selected = [];
                if (event && event.target && event.target.files) {
                    selected = Array.prototype.slice.call(event.target.files);
                }

                if (selected.length === 0) {
                    return;
                }

                clearAttachmentError();

                var result = addAttachments(selected);
                updateAttachmentState();

                $mobilePhotoInput.val('');

                if (result.error) {
                    showAttachmentError(result.error);
                    notifyMobileFeedback(result.error, 'error');
                } else if (result.added > 0) {
                    notifyMobileFeedback(mobilePhotoSuccessText, 'success');
                }
            });
        }

        if ($mobileAnnotationButton.length) {
            $mobileAnnotationButton.on('click', function (event) {
                event.preventDefault();

                if (!mobileModeEnabled) {
                    setMobileMode(true, false);
                }

                refreshAnnotationOptions();

                if (annotationModalInstance && typeof annotationModalInstance.show === 'function') {
                    annotationModalInstance.show();
                } else if ($mobileAnnotationModal.length && typeof $mobileAnnotationModal.modal === 'function') {
                    $mobileAnnotationModal.modal('show');
                }
            });
        }

        if ($mobileAnnotationModal.length) {
            $mobileAnnotationModal.on('shown.bs.modal', function () {
                refreshAnnotationOptions();
                annotationStrokeColor = getAnnotationStrokeColor();
            });

            $mobileAnnotationModal.on('hidden.bs.modal', function () {
                annotationDrawing = false;
            });
        }

        if ($annotationImageSelect.length) {
            $annotationImageSelect.on('change', function () {
                var value = $(this).val();
                var index = Array.isArray(value) ? Number(value[0]) : Number(value);
                if (!Number.isFinite(index)) {
                    index = -1;
                }

                loadAnnotationSource(index);
            });
        }

        if ($annotationColorInput.length) {
            $annotationColorInput.on('change input', function () {
                annotationStrokeColor = getAnnotationStrokeColor();
            });
        }

        if ($annotationClear.length) {
            $annotationClear.on('click', function (event) {
                event.preventDefault();
                clearAnnotationDrawing();
            });
        }

        if ($annotationSave.length) {
            $annotationSave.on('click', function (event) {
                event.preventDefault();
                saveAnnotationAsAttachment();
            });
        }

        if (annotationCanvasElement && annotationCanvasElement.addEventListener) {
            annotationCanvasElement.addEventListener('pointerdown', startAnnotationDrawing);
            annotationCanvasElement.addEventListener('pointermove', continueAnnotationDrawing);
            annotationCanvasElement.addEventListener('pointerup', finishAnnotationDrawing);
            annotationCanvasElement.addEventListener('pointercancel', finishAnnotationDrawing);
            annotationCanvasElement.addEventListener('pointerleave', finishAnnotationDrawing);
        }

        if ($mobileBarcodeButton.length) {
            $mobileBarcodeButton.on('click', function (event) {
                event.preventDefault();

                if (!mobileModeEnabled) {
                    setMobileMode(true, false);
                }

                resetBarcodeStatus();

                if (barcodeModalInstance && typeof barcodeModalInstance.show === 'function') {
                    barcodeModalInstance.show();
                } else if ($barcodeModal.length && typeof $barcodeModal.modal === 'function') {
                    $barcodeModal.modal('show');
                } else {
                    notifyMobileFeedback(mobileBarcodeUnsupportedText, 'error');
                }
            });
        }

        if ($barcodeModal.length) {
            $barcodeModal.on('shown.bs.modal', function () {
                startBarcodeScanning();
            });

            $barcodeModal.on('hidden.bs.modal', function () {
                stopBarcodeScanning();
                resetBarcodeStatus();
            });
        }

        if ($signatureToggle.length) {
            $signatureToggle.on('click', function (event) {
                event.preventDefault();

                if (!mobileModeEnabled) {
                    setMobileMode(true, false);
                }

                if ($signaturePadContainer.length) {
                    var isVisible = ! $signaturePadContainer.hasClass('d-none');
                    if (isVisible) {
                        $signaturePadContainer.addClass('d-none');
                    } else {
                        $signaturePadContainer.removeClass('d-none');
                        resizeSignatureCanvas(false);
                    }
                }
            });
        }

        if ($signatureClear.length) {
            $signatureClear.on('click', function (event) {
                event.preventDefault();
                clearSignatureCanvas();
            });
        }

        if ($signatureSave.length) {
            $signatureSave.on('click', function (event) {
                event.preventDefault();
                saveSignatureAsAttachment();
            });
        }

        if (signatureCanvas && signatureCanvas.addEventListener) {
            signatureCanvas.addEventListener('pointerdown', startSignatureDrawing);
            signatureCanvas.addEventListener('pointermove', continueSignatureDrawing);
            signatureCanvas.addEventListener('pointerup', finishSignatureDrawing);
            signatureCanvas.addEventListener('pointerleave', finishSignatureDrawing);
            signatureCanvas.addEventListener('pointercancel', finishSignatureDrawing);
        }

        $(window).on('resize', function () {
            if (mobileModeEnabled) {
                resizeSignatureCanvas(false);
            }
        });

        updateSignatureButtons();

        var initialMobileMode = determineInitialMobileMode();
        setMobileMode(initialMobileMode, true);

        if ($mobileToggle.length) {
            $mobileToggle.on('click', function (event) {
                event.preventDefault();
                setMobileMode(!mobileModeEnabled, false);
            });
        }

        updateAttachmentState();

        function setReferenceValue(value, isAutogenerated) {
            if (!$referenciaInput.length) {
                return;
            }

            $referenciaInput.val(value);

            if (isAutogenerated && value) {
                lastAutoGeneratedReference = value;
                $referenciaInput.attr('data-autogenerated', 'true');
            } else {
                lastAutoGeneratedReference = '';
                $referenciaInput.removeAttr('data-autogenerated');
            }
        }

        function cancelPendingReferenceRequest() {
            if (pendingReferenceRequest && typeof pendingReferenceRequest.abort === 'function') {
                pendingReferenceRequest.abort();
            }

            pendingReferenceRequest = null;
        }

        function requestNextReference(clientId) {
            if (!$referenciaInput.length) {
                return;
            }

            if (!clientId) {
                if (lastAutoGeneratedReference !== '') {
                    setReferenceValue('', false);
                }

                cancelPendingReferenceRequest();
                return;
            }

            var currentValue = ($referenciaInput.val() || '').trim();
            if (currentValue !== '' && currentValue !== lastAutoGeneratedReference) {
                cancelPendingReferenceRequest();
                return;
            }

            cancelPendingReferenceRequest();

            var requestClientId = String(clientId);
            pendingReferenceRequest = $.ajax({
                url: '../api/desembarques/next_reference.php',
                method: 'GET',
                data: {
                    client_id: requestClientId,
                },
                dataType: 'json',
            });

            pendingReferenceRequest.done(function (response) {
                pendingReferenceRequest = null;

                if (($clienteSelect.val() || '').trim() !== requestClientId) {
                    return;
                }

                if (response && response.success && response.reference) {
                    setReferenceValue(response.reference, true);
                    return;
                }

                if (lastAutoGeneratedReference !== '') {
                    var latestValue = ($referenciaInput.val() || '').trim();
                    if (latestValue === lastAutoGeneratedReference) {
                        setReferenceValue('', false);
                    }
                }
            });

            pendingReferenceRequest.fail(function () {
                pendingReferenceRequest = null;

                if (($clienteSelect.val() || '').trim() !== requestClientId) {
                    return;
                }

                if (lastAutoGeneratedReference !== '') {
                    var latestValue = ($referenciaInput.val() || '').trim();
                    if (latestValue === lastAutoGeneratedReference) {
                        setReferenceValue('', false);
                    }
                }
            });
        }

        function renderClienteInfo(clientName, clientEmail) {
            if (!clientName && !clientEmail) {
                $clienteSelectInfo.addClass('d-none').text('');
                return;
            }

            var template = clienteInfoTemplate;
            if (template) {
                var nameValue = clientName || translate('dashboard.form.customer_assign_placeholder', '');
                var emailValue = clientEmail || '';
                template = template.replace(/\{\{\s*name\s*\}\}/g, nameValue);
                template = template.replace(/\{\{\s*email\s*\}\}/g, emailValue);
                $clienteSelectInfo.text(template).removeClass('d-none');
            } else {
                var displayText = clientName;
                if (clientEmail) {
                    displayText = displayText ? displayText + ' (' + clientEmail + ')' : clientEmail;
                }
                $clienteSelectInfo.text(displayText).removeClass('d-none');
            }
        }

        function handleClienteSelectionChange() {
            if (!$clienteSelect.length) {
                return;
            }

            var $selected = $clienteSelect.find('option:selected');
            var clientId = ($selected.val() || '').trim();
            var clientName = ($selected.attr('data-client-name') || '').trim();
            var clientEmail = ($selected.attr('data-client-email') || '').trim();
            var valueToApply = clientName || clientEmail;

            if ($clienteInput.length) {
                $clienteInput.val(valueToApply);
            }

            if (clientName || clientEmail) {
                renderClienteInfo(clientName, clientEmail);
            } else {
                $clienteSelectInfo.addClass('d-none').text('');
            }

            requestNextReference(clientId);
        }

        if ($clienteSelect.length) {
            $clienteSelect.on('change', handleClienteSelectionChange);
            handleClienteSelectionChange();
        }

        if ($referenciaInput.length) {
            $referenciaInput.on('input', function () {
                lastAutoGeneratedReference = '';
                $referenciaInput.removeAttr('data-autogenerated');
                cancelPendingReferenceRequest();
            });
        }

        function updateDerivedFields() {
            var fechaDesembarqueDate = parseDate($fechaDesembarque.val());
            var fechaEmbarqueDate = parseDate($fechaEmbarque.val());
            var today = new Date();
            var todayUtc = new Date(Date.UTC(today.getFullYear(), today.getMonth(), today.getDate()));

            var diasTranscurridosValue = '';
            if (fechaDesembarqueDate instanceof Date) {
                var diffToToday = calculateDaysDifference(todayUtc, fechaDesembarqueDate);
                if (diffToToday !== null) {
                    diasTranscurridosValue = Math.max(diffToToday, 0);
                }
            }

            var diasFueraValue = '';
            if (fechaDesembarqueDate instanceof Date && fechaEmbarqueDate instanceof Date) {
                var diff = calculateDaysDifference(fechaEmbarqueDate, fechaDesembarqueDate);
                if (diff !== null && diff >= 0) {
                    diasFueraValue = diff;
                }
            }

            if (diasTranscurridosValue === '') {
                diasTranscurridosValue = 0;
            }

            if (diasFueraValue === '') {
                diasFueraValue = 0;
            }

            $diasTranscurridos.val(diasTranscurridosValue);
            $diasTranscurridosDisplay.val(diasTranscurridosValue);
            $diasFuera.val(diasFueraValue);
            $diasFueraDisplay.val(diasFueraValue);

            return {
                diasTranscurridos: diasTranscurridosValue,
                diasFuera: diasFueraValue,
            };
        }

        $fechaDesembarque.on('change', updateDerivedFields);
        $fechaEmbarque.on('change', updateDerivedFields);
        $form.on('reset', function () {
            window.setTimeout(function () {
                $diasTranscurridos.val(0);
                $diasTranscurridosDisplay.val(0);
                $diasFuera.val(0);
                $diasFueraDisplay.val(0);
                if ($referenciaInput.length) {
                    setReferenceValue('', false);
                }
                if ($clienteSelect.length) {
                    $clienteSelect.prop('selectedIndex', 0);
                    $clienteSelect.trigger('change');
                }
                if ($statusSelect.length) {
                    if (statusDefaultValue !== '') {
                        $statusSelect.val(statusDefaultValue);
                    } else {
                        $statusSelect.prop('selectedIndex', 0);
                    }
                }
                clearAttachments();
                hideSignaturePad();
                hideMobileFeedback();
                dynamicFieldGroups.forEach(function (group) {
                    if (group && typeof group.reset === 'function') {
                        group.reset();
                    }
                });
            }, 0);
        });

        $form.on('submit', function (event) {
            event.preventDefault();
            var derived = updateDerivedFields();

            var formData = new FormData($form[0]);
            if (!dataTransferSupported) {
                attachments.forEach(function (file, index) {
                    if (file) {
                        var filename = file.name && file.name.length ? file.name : 'attachment-' + (index + 1);
                        formData.append('attachments[]', file, filename);
                    }
                });
            }
            clearAttachmentError();

            if (offlineQueueEnabled && typeof navigator !== 'undefined' && navigator && navigator.onLine === false) {
                queueSubmissionOffline(formData)
                    .then(function () {
                        showOfflineQueueSavedAlert();
                        $form[0].reset();
                        updateDerivedFields();
                    })
                    .catch(function (queueError) {
                        handleOfflineQueueFailure(queueError);
                    });
                return;
            }

            $.ajax({
                url: '../api/desembarques/store.php',
                method: 'POST',
                data: formData,
                dataType: 'json',
                processData: false,
                contentType: false
            })
                .done(function (response) {
                    if (response && response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: translate('desembarques.alert.success_title', 'Registro guardado'),
                            text: response.message || translate('desembarques.alert.success_message', 'El desembarque se guardó correctamente.'),
                        });
                        $form[0].reset();
                        updateDerivedFields();
                        if (offlineQueueEnabled && offlineQueue && typeof offlineQueue.processQueue === 'function') {
                            offlineQueue.processQueue().catch(function (error) {
                                console.error('Failed to process offline queue after submission:', error);
                            });
                        }
                    } else {
                        Swal.fire({
                            icon: 'warning',
                            title: translate('common.validation_title', 'Validación'),
                            text: response && response.message ? response.message : translate('desembarques.alert.validation_message', 'No se pudo validar la información proporcionada.'),
                        });
                        if (response && response.errors && response.errors.attachments) {
                            showAttachmentError(response.errors.attachments);
                        }
                    }
                })
                .fail(function (jqXHR) {
                    var status = jqXHR.status;
                    if (offlineQueueEnabled && (status === 0 || (typeof navigator !== 'undefined' && navigator && navigator.onLine === false))) {
                        queueSubmissionOffline(formData)
                            .then(function () {
                                showOfflineQueueSavedAlert();
                                $form[0].reset();
                                updateDerivedFields();
                            })
                            .catch(function (queueError) {
                                handleOfflineQueueFailure(queueError);
                            });
                        return;
                    }

                    var message = translate('desembarques.alert.error_generic', 'Ocurrió un error al enviar la información.');
                    var icon = 'error';
                    var title = translate('common.error_title', 'Error');

                    if (status === 401) {
                        message = translate('common.session_expired', 'Tu sesión ha expirado. Por favor, inicia sesión nuevamente.');
                        icon = 'warning';
                        title = translate('common.warning_title', 'Atención');
                        window.setTimeout(function () {
                            window.location.href = 'login.php?status=expired&lang=' + encodeURIComponent(currentLanguage);
                        }, 1500);
                    } else if (status === 403) {
                        message = translate('desembarques.permission_denied', 'No cuentas con permisos para registrar desembarques.');
                        icon = 'warning';
                        title = translate('common.warning_title', 'Atención');
                        if (jqXHR.responseJSON && jqXHR.responseJSON.message) {
                            message = jqXHR.responseJSON.message;
                        }
                    } else if (status === 419) {
                        message = jqXHR.responseJSON && jqXHR.responseJSON.message
                            ? jqXHR.responseJSON.message
                            : translate('common.csrf_token_invalid', 'La seguridad del formulario expiró. Actualiza la página e inténtalo de nuevo.');
                        icon = 'warning';
                        title = translate('common.warning_title', 'Atención');
                    } else if (jqXHR.responseJSON && jqXHR.responseJSON.message) {
                        message = jqXHR.responseJSON.message;
                    }

                    Swal.fire({
                        icon: icon,
                        title: title,
                        text: message,
                    });

                    if (jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.errors && jqXHR.responseJSON.errors.attachments) {
                        showAttachmentError(jqXHR.responseJSON.errors.attachments);
                    }
                });
        });

        updateDerivedFields();
    });
})(jQuery);
