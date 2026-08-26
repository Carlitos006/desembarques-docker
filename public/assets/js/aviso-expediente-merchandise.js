(function () {
    'use strict';

    var appConfig = window.AppConfig && window.AppConfig.avisoExpediente
        ? window.AppConfig.avisoExpediente
        : {};
    var language = window.AppConfig && window.AppConfig.language === 'en' ? 'en' : 'es';

    function text(es, en) {
        return language === 'en' ? en : es;
    }

    function jsonFetch(url, payload) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(payload)
        }).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (data) {
                if (!response.ok || !data || data.success !== true) {
                    throw new Error(data && data.message
                        ? data.message
                        : text('No fue posible completar la operación.', 'Unable to complete the operation.'));
                }
                return data;
            });
        });
    }

    function reloadMerchandise(extraKey) {
        var url = new URL(window.location.href);
        url.searchParams.set('id', String(appConfig.desembarqueId || ''));
        url.searchParams.set('tab', 'mercancias');
        if (extraKey) {
            url.searchParams.set(extraKey, '1');
        }
        window.location.href = url.toString();
    }

    // ---------------------------------------------------------------------
    // Registro de salidas V2: múltiples eventos + datos aduanales por línea
    // ---------------------------------------------------------------------
    var form = document.getElementById('merchandise-finalize-form');
    if (form && appConfig.merchandiseFinalizeEndpoint && appConfig.canFinalizeMerchandise) {
        var feedback = document.getElementById('merchandise-finalize-feedback');
        var submit = document.getElementById('merchandise-finalize-submit');
        var selectAll = document.getElementById('merchandise-finalize-select-all');
        var selectedCount = document.getElementById('merchandise-finalize-selected-count');
        var noSelection = document.getElementById('merchandise-finalize-no-selection');
        var closureInputs = Array.prototype.slice.call(form.querySelectorAll('input[name="closure_status"]'));
        var closureHelp = document.getElementById('merchandise-closure-help');
        var customsHelp = document.getElementById('merchandise-finalize-customs-help');
        var notesInput = document.getElementById('merchandise-finalize-notes');
        var notesRequired = document.getElementById('merchandise-finalize-notes-required');
        var notesHelp = document.getElementById('merchandise-finalize-notes-help');
        var itemRows = Array.prototype.slice.call(form.querySelectorAll('[data-finalize-item-row]'));
        var detailCards = Array.prototype.slice.call(form.querySelectorAll('[data-finalize-item-detail]'));

        function selectedClosureStatus() {
            var selected = closureInputs.find(function (input) { return input.checked; });
            return selected && selected.value === 'partial' ? 'partial' : 'complete';
        }

        function syncClosureMode() {
            var isPartial = selectedClosureStatus() === 'partial';
            if (closureHelp) {
                closureHelp.textContent = isPartial
                    ? text('Los datos aduanales pueden quedar vacíos temporalmente. Las observaciones son obligatorias para identificar lo pendiente.', 'Customs fields may remain temporarily blank. Notes are required to identify what is pending.')
                    : text('El cierre completo requiere al menos un dato aduanal por cada mercancía seleccionada.', 'Complete closure requires at least one customs field for each selected merchandise line.');
            }
            if (customsHelp) {
                customsHelp.textContent = isPartial
                    ? text('Captura los datos aduanales que ya tengas. Podrás completar o corregirlos desde el historial de la salida.', 'Enter any customs information already available. You can complete or correct it later from the departure history.')
                    : text('Cada renglón seleccionado tiene su propia cantidad de salida y sus propios datos aduanales. Para un cierre completo, al menos un dato aduanal es obligatorio por cada mercancía.', 'Each selected merchandise line has its own departure quantity and customs data. A complete closure requires at least one customs field per merchandise line.');
            }
            if (notesInput) {
                notesInput.required = isPartial;
                notesInput.minLength = isPartial ? 5 : 0;
                notesInput.placeholder = isPartial
                    ? text('Indica qué pedimentos o documentos están pendientes', 'Specify which customs entries or documents are pending')
                    : text('Nota opcional sobre esta salida', 'Optional note about this departure');
            }
            if (notesRequired) {
                notesRequired.classList.toggle('d-none', !isPartial);
            }
            if (notesHelp) {
                notesHelp.classList.toggle('d-none', !isPartial);
            }
            if (submit && !submit.disabled) {
                submit.textContent = isPartial
                    ? text('Guardar cierre parcial', 'Save partial closure')
                    : text('Guardar cierre completo', 'Save complete closure');
            }
        }

        closureInputs.forEach(function (input) {
            input.addEventListener('change', function () {
                syncClosureMode();
                setFeedback('info', '');
            });
        });
        syncClosureMode();

        function setFeedback(type, message) {
            if (!feedback) {
                return;
            }
            feedback.className = 'alert mt-4 mb-0 alert-' + type;
            feedback.textContent = message || '';
            feedback.classList.toggle('d-none', !message);
        }

        function checkboxFor(row) {
            return row.querySelector('.merchandise-finalize-item-check');
        }

        function detailFor(itemId) {
            return detailCards.find(function (detail) {
                return String(detail.dataset.itemId || '') === String(itemId || '');
            }) || null;
        }

        function rowDescription(row) {
            var detail = detailFor(row.dataset.itemId || '');
            if (detail) {
                var title = detail.querySelector('summary .fw-semibold');
                if (title) {
                    return String(title.textContent || '').trim();
                }
            }
            var titleInRow = row.querySelector('.fw-semibold');
            return titleInRow ? String(titleInRow.textContent || '').trim() : '';
        }

        function customsValues(detail) {
            var values = {};
            if (!detail) {
                return values;
            }
            detail.querySelectorAll('.merchandise-customs-field[data-customs-key]').forEach(function (field) {
                values[String(field.dataset.customsKey || '')] = String(field.value || '').trim();
            });
            return values;
        }

        function hasCustomsData(values) {
            return Object.keys(values).some(function (key) {
                return String(values[key] || '').trim() !== '';
            });
        }

        function selectedRows() {
            return itemRows.filter(function (row) {
                var checkbox = checkboxFor(row);
                return checkbox && checkbox.checked;
            });
        }

        function syncSelectedCount() {
            var count = selectedRows().length;
            if (selectedCount) {
                selectedCount.textContent = count + ' ' + text(count === 1 ? 'seleccionada' : 'seleccionadas', count === 1 ? 'selected' : 'selected');
            }
            if (noSelection) {
                noSelection.classList.toggle('d-none', count > 0);
            }
        }

        function syncSelectAllState() {
            if (!selectAll || itemRows.length === 0) {
                return;
            }
            var checks = itemRows.map(checkboxFor).filter(Boolean);
            var checked = checks.filter(function (checkbox) { return checkbox.checked; }).length;
            selectAll.checked = checked > 0 && checked === checks.length;
            selectAll.indeterminate = checked > 0 && checked < checks.length;
        }

        function syncRow(row, openOnSelect) {
            var checkbox = checkboxFor(row);
            if (!checkbox) {
                return;
            }
            var itemId = String(row.dataset.itemId || '');
            var detail = detailFor(itemId);
            row.classList.toggle('merchandise-finalize-selected', checkbox.checked);
            if (detail) {
                detail.classList.toggle('d-none', !checkbox.checked);
                if (checkbox.checked && openOnSelect) {
                    detail.open = true;
                }
            }
            syncSelectAllState();
            syncSelectedCount();
        }

        itemRows.forEach(function (row) {
            var checkbox = checkboxFor(row);
            if (!checkbox) {
                return;
            }
            checkbox.addEventListener('change', function () {
                syncRow(row, true);
                setFeedback('info', '');
            });
            syncRow(row, false);
        });

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                itemRows.forEach(function (row) {
                    var checkbox = checkboxFor(row);
                    if (checkbox) {
                        checkbox.checked = !!selectAll.checked;
                        syncRow(row, !!selectAll.checked);
                    }
                });
                setFeedback('info', '');
            });
        }

        form.querySelectorAll('[data-copy-customs-from]').forEach(function (button) {
            button.addEventListener('click', function () {
                var sourceId = String(button.dataset.copyCustomsFrom || '');
                var sourceDetail = detailFor(sourceId);
                if (!sourceDetail) {
                    return;
                }
                var values = customsValues(sourceDetail);
                if (!hasCustomsData(values)) {
                    setFeedback('warning', text('Captura primero al menos un dato aduanal en esta mercancía.', 'Enter at least one customs value for this merchandise line first.'));
                    return;
                }

                var copied = 0;
                selectedRows().forEach(function (row) {
                    var itemId = String(row.dataset.itemId || '');
                    if (itemId === sourceId) {
                        return;
                    }
                    var target = detailFor(itemId);
                    if (!target) {
                        return;
                    }
                    target.querySelectorAll('.merchandise-customs-field[data-customs-key]').forEach(function (field) {
                        var key = String(field.dataset.customsKey || '');
                        field.value = values[key] || '';
                    });
                    copied++;
                });

                setFeedback('success', copied > 0
                    ? text('Datos aduanales copiados a las demás mercancías seleccionadas.', 'Customs data copied to the other selected merchandise lines.')
                    : text('No hay otras mercancías seleccionadas a las cuales copiar.', 'There are no other selected merchandise lines to copy to.'));
            });
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            setFeedback('info', '');

            var closureStatus = selectedClosureStatus();

            if (!form.reportValidity()) {
                return;
            }

            var rows = selectedRows();
            if (rows.length === 0) {
                setFeedback('warning', text('Selecciona al menos una mercancía.', 'Select at least one merchandise line.'));
                return;
            }

            var items = [];
            var validationError = '';
            rows.some(function (row) {
                var itemId = String(row.dataset.itemId || '');
                var detail = detailFor(itemId);
                var description = rowDescription(row) || ('#' + itemId);
                if (!detail) {
                    validationError = text('No se encontró el detalle para "' + description + '".', 'Details were not found for "' + description + '".');
                    return true;
                }

                var quantityInput = detail.querySelector('.merchandise-finalize-quantity');
                var quantity = quantityInput ? Number(String(quantityInput.value || '').replace(',', '.')) : NaN;
                var max = quantityInput ? Number(String(quantityInput.dataset.max || '0').replace(',', '.')) : 0;
                if (!Number.isFinite(quantity) || quantity <= 0 || !Number.isFinite(max) || quantity > max + 0.0005) {
                    validationError = text('Revisa la cantidad de salida para "' + description + '". No puede superar el saldo disponible.', 'Review the departure quantity for "' + description + '". It cannot exceed the available balance.');
                    detail.open = true;
                    return true;
                }

                var customs = customsValues(detail);
                if (closureStatus === 'complete' && !hasCustomsData(customs)) {
                    validationError = text('Captura al menos un dato aduanal para "' + description + '".', 'Enter at least one customs field for "' + description + '".');
                    detail.open = true;
                    return true;
                }

                items.push({
                    item_id: itemId,
                    description: description,
                    quantity: quantity,
                    pedimento_r1_agregar: customs.pedimento_r1_agregar || '',
                    pedimento_retorno_parcial_h1: customs.pedimento_retorno_parcial_h1 || '',
                    mercancia_pedimento_h1: customs.mercancia_pedimento_h1 || '',
                    pedimento_r1_desagregado: customs.pedimento_r1_desagregado || '',
                    pedimento_a3: customs.pedimento_a3 || ''
                });
                return false;
            });

            if (validationError) {
                setFeedback('warning', validationError);
                return;
            }

            var exportDate = document.getElementById('merchandise-finalize-date');
            var notes = notesInput;
            var payload = {
                csrf_token: String(appConfig.csrfToken || ''),
                desembarque_id: String(appConfig.desembarqueId || ''),
                export_date: exportDate ? String(exportDate.value || '') : '',
                closure_status: closureStatus,
                notes: notes ? String(notes.value || '').trim() : '',
                items: items
            };

            if (submit) {
                submit.disabled = true;
                submit.dataset.originalText = submit.dataset.originalText || submit.textContent;
                submit.textContent = text('Guardando…', 'Saving…');
            }

            jsonFetch(appConfig.merchandiseFinalizeEndpoint, payload)
                .then(function () {
                    setFeedback('success', closureStatus === 'partial'
                        ? text('Salida registrada con cierre parcial. Recargando expediente…', 'Departure recorded with a partial closure. Reloading case file…')
                        : text('Salida registrada con cierre completo. Recargando expediente…', 'Departure recorded with a complete closure. Reloading case file…'));
                    window.setTimeout(function () { reloadMerchandise('finalized'); }, 450);
                })
                .catch(function (error) {
                    setFeedback('danger', error && error.message
                        ? error.message
                        : text('No fue posible registrar la salida de mercancía.', 'Unable to record merchandise departure.'));
                })
                .finally(function () {
                    if (submit) {
                        submit.disabled = false;
                        syncClosureMode();
                    }
                });
        });
    }

    // ---------------------------------------------------------------------
    // Completar un cierre parcial sin crear otra salida ni alterar cantidades
    // ---------------------------------------------------------------------
    var completeForm = document.getElementById('merchandise-finalization-complete-form');
    var completeModalElement = document.getElementById('merchandiseFinalizationCompleteModal');
    var completeIdInput = document.getElementById('merchandise-finalization-complete-id');
    var completeLabel = document.getElementById('merchandise-finalization-complete-label');
    var completeFeedback = document.getElementById('merchandise-finalization-complete-feedback');
    var completeSubmit = document.getElementById('merchandise-finalization-complete-submit');
    var completionGroups = completeForm
        ? Array.prototype.slice.call(completeForm.querySelectorAll('[data-completion-finalization]'))
        : [];

    function setCompleteFeedback(type, message) {
        if (!completeFeedback) {
            return;
        }
        completeFeedback.className = 'alert mt-3 mb-0 alert-' + type;
        completeFeedback.textContent = message || '';
        completeFeedback.classList.toggle('d-none', !message);
    }

    function completionGroupFor(finalizationId) {
        return completionGroups.find(function (group) {
            return String(group.dataset.completionFinalization || '') === String(finalizationId || '');
        }) || null;
    }

    function completionCustomsValues(line) {
        var values = {};
        line.querySelectorAll('.merchandise-completion-customs-field[data-customs-key]').forEach(function (field) {
            values[String(field.dataset.customsKey || '')] = String(field.value || '').trim();
        });
        return values;
    }

    function completionHasCustomsData(values) {
        return Object.keys(values).some(function (key) {
            return String(values[key] || '').trim() !== '';
        });
    }

    document.querySelectorAll('[data-finalization-complete]').forEach(function (button) {
        button.addEventListener('click', function () {
            var finalizationId = String(button.dataset.finalizationComplete || '');
            var activeGroup = completionGroupFor(finalizationId);
            if (!completeModalElement || !completeIdInput || !activeGroup || !appConfig.merchandiseFinalizationCompleteEndpoint) {
                return;
            }

            completeIdInput.value = finalizationId;
            completionGroups.forEach(function (group) {
                group.classList.toggle('d-none', group !== activeGroup);
            });
            if (completeLabel) {
                completeLabel.textContent = String(button.dataset.finalizationLabel || '');
            }
            setCompleteFeedback('info', '');
            if (window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(completeModalElement).show();
            }
        });
    });

    if (completeForm && appConfig.merchandiseFinalizationCompleteEndpoint) {
        completeForm.addEventListener('submit', function (event) {
            event.preventDefault();
            setCompleteFeedback('info', '');

            var finalizationId = completeIdInput ? String(completeIdInput.value || '') : '';
            var activeGroup = completionGroupFor(finalizationId);
            if (!finalizationId || !activeGroup) {
                setCompleteFeedback('warning', text('Selecciona un cierre parcial válido.', 'Select a valid partial closure.'));
                return;
            }

            var items = [];
            var missingCustoms = false;
            activeGroup.querySelectorAll('[data-completion-line]').forEach(function (line) {
                var customs = completionCustomsValues(line);
                if (!completionHasCustomsData(customs)) {
                    missingCustoms = true;
                    return;
                }
                items.push({
                    line_id: String(line.dataset.lineId || ''),
                    pedimento_r1_agregar: customs.pedimento_r1_agregar || '',
                    pedimento_retorno_parcial_h1: customs.pedimento_retorno_parcial_h1 || '',
                    mercancia_pedimento_h1: customs.mercancia_pedimento_h1 || '',
                    pedimento_r1_desagregado: customs.pedimento_r1_desagregado || '',
                    pedimento_a3: customs.pedimento_a3 || ''
                });
            });

            if (missingCustoms || items.length === 0) {
                setCompleteFeedback('warning', text(
                    'Captura al menos un dato aduanal por cada mercancía antes de completar el cierre.',
                    'Enter at least one customs field for each merchandise line before completing the closure.'
                ));
                return;
            }

            if (completeSubmit) {
                completeSubmit.disabled = true;
                completeSubmit.dataset.originalText = completeSubmit.dataset.originalText || completeSubmit.textContent;
                completeSubmit.textContent = text('Completando…', 'Completing…');
            }

            jsonFetch(appConfig.merchandiseFinalizationCompleteEndpoint, {
                csrf_token: String(appConfig.csrfToken || ''),
                desembarque_id: String(appConfig.desembarqueId || ''),
                finalization_id: finalizationId,
                items: items
            }).then(function () {
                setCompleteFeedback('success', text(
                    'Cierre documental completado. Recargando expediente…',
                    'Documentary closure completed. Reloading case file…'
                ));
                window.setTimeout(function () { reloadMerchandise('closure_completed'); }, 450);
            }).catch(function (error) {
                setCompleteFeedback('danger', error && error.message
                    ? error.message
                    : text('No fue posible completar el cierre documental.', 'Unable to complete the documentary closure.'));
            }).finally(function () {
                if (completeSubmit) {
                    completeSubmit.disabled = false;
                    completeSubmit.textContent = completeSubmit.dataset.originalText || text('Completar cierre', 'Complete closure');
                }
            });
        });
    }

    // ---------------------------------------------------------------------
    // Anulación auditada: el movimiento permanece, pero deja de afectar saldo
    // ---------------------------------------------------------------------
    var voidForm = document.getElementById('merchandise-finalization-void-form');
    var voidModalElement = document.getElementById('merchandiseFinalizationVoidModal');
    var voidIdInput = document.getElementById('merchandise-finalization-void-id');
    var voidReason = document.getElementById('merchandise-finalization-void-reason');
    var voidLabel = document.getElementById('merchandise-finalization-void-label');
    var voidFeedback = document.getElementById('merchandise-finalization-void-feedback');
    var voidSubmit = document.getElementById('merchandise-finalization-void-submit');

    function setVoidFeedback(type, message) {
        if (!voidFeedback) {
            return;
        }
        voidFeedback.className = 'alert mt-3 mb-0 alert-' + type;
        voidFeedback.textContent = message || '';
        voidFeedback.classList.toggle('d-none', !message);
    }

    document.querySelectorAll('[data-finalization-void]').forEach(function (button) {
        button.addEventListener('click', function () {
            if (!voidModalElement || !voidIdInput || !appConfig.merchandiseFinalizationVoidEndpoint) {
                return;
            }
            voidIdInput.value = String(button.dataset.finalizationVoid || '');
            if (voidLabel) {
                voidLabel.textContent = String(button.dataset.finalizationLabel || '');
            }
            if (voidReason) {
                voidReason.value = '';
            }
            setVoidFeedback('info', '');
            if (window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(voidModalElement).show();
            }
        });
    });

    if (voidForm && appConfig.merchandiseFinalizationVoidEndpoint) {
        voidForm.addEventListener('submit', function (event) {
            event.preventDefault();
            setVoidFeedback('info', '');
            if (!voidForm.reportValidity()) {
                return;
            }

            var reason = voidReason ? String(voidReason.value || '').trim() : '';
            var finalizationId = voidIdInput ? String(voidIdInput.value || '') : '';
            if (reason.length < 5 || !finalizationId) {
                setVoidFeedback('warning', text('Indica un motivo válido para la anulación.', 'Enter a valid reason for voiding this movement.'));
                return;
            }

            if (voidSubmit) {
                voidSubmit.disabled = true;
                voidSubmit.dataset.originalText = voidSubmit.dataset.originalText || voidSubmit.textContent;
                voidSubmit.textContent = text('Anulando…', 'Voiding…');
            }

            jsonFetch(appConfig.merchandiseFinalizationVoidEndpoint, {
                csrf_token: String(appConfig.csrfToken || ''),
                desembarque_id: String(appConfig.desembarqueId || ''),
                finalization_id: finalizationId,
                reason: reason
            }).then(function () {
                setVoidFeedback('success', text('Movimiento anulado. Recargando saldos…', 'Movement voided. Reloading balances…'));
                window.setTimeout(function () { reloadMerchandise('movement_voided'); }, 450);
            }).catch(function (error) {
                setVoidFeedback('danger', error && error.message
                    ? error.message
                    : text('No fue posible anular el movimiento.', 'Unable to void the movement.'));
            }).finally(function () {
                if (voidSubmit) {
                    voidSubmit.disabled = false;
                    voidSubmit.textContent = voidSubmit.dataset.originalText || text('Anular movimiento', 'Void movement');
                }
            });
        });
    }
})();
