(function () {
    'use strict';

    var config = window.ControlTowerConfig || {};
    var boardElement = document.querySelector('[data-control-tower-board]');

    if (!boardElement) {
        return;
    }

    var translations = config.translations && typeof config.translations === 'object'
        ? config.translations
        : {};
    var statuses = Array.isArray(config.statuses) ? config.statuses.slice() : [];
    var alertElement = document.getElementById('controlTowerAlert');
    var refreshButton = document.querySelector('[data-control-tower-refresh]');
    var lastRefreshedElement = document.querySelector('[data-control-tower-last-refreshed]');
    var dropzones = Array.prototype.slice.call(boardElement.querySelectorAll('.control-tower-dropzone'));
    var columnsByStatus = {};
    var language = typeof config.language === 'string' && config.language ? config.language : 'es';
    var csrfToken = typeof config.csrfToken === 'string' ? config.csrfToken : '';
    var endpoints = config.endpoints || {};
    var isLoading = false;
    var isUpdating = false;
    var dragContext = null;
    var hideAlertTimeout = null;
    var emptyLabel = boardElement.getAttribute('data-empty-label') || '';
    var slaTargetHours = Number(boardElement.getAttribute('data-sla-target-hours') || 0);
    var slaWarningHours = Number(boardElement.getAttribute('data-sla-warning-hours') || 0);
    var dateFormatter;
    var timeFormatter;

    try {
        dateFormatter = new Intl.DateTimeFormat(language, { dateStyle: 'medium' });
    } catch (error) {
        dateFormatter = new Intl.DateTimeFormat('es', { dateStyle: 'medium' });
    }

    try {
        timeFormatter = new Intl.DateTimeFormat(language, { dateStyle: 'short', timeStyle: 'short' });
    } catch (error) {
        timeFormatter = new Intl.DateTimeFormat('es', { dateStyle: 'short', timeStyle: 'short' });
    }

    statuses.forEach(function (status) {
        var statusId = Number(status && status.id);

        if (!Number.isFinite(statusId) || statusId <= 0) {
            return;
        }

        var column = boardElement.querySelector('[data-status-column="' + statusId + '"]');

        if (column) {
            columnsByStatus[statusId] = column;
        }
    });

    function translate(key, fallback, replacements) {
        var value;

        if (Object.prototype.hasOwnProperty.call(translations, key)) {
            value = translations[key];
        } else if (fallback !== undefined) {
            value = fallback;
        } else {
            value = '';
        }

        value = String(value);

        if (replacements && typeof replacements === 'object') {
            Object.keys(replacements).forEach(function (placeholder) {
                if (!Object.prototype.hasOwnProperty.call(replacements, placeholder)) {
                    return;
                }

                var pattern = new RegExp('\\{\\{\\s*' + placeholder + '\\s*\\}\\}', 'g');
                value = value.replace(pattern, String(replacements[placeholder]));
            });
        }

        return value;
    }

    function clearAlert() {
        if (!alertElement) {
            return;
        }

        alertElement.className = 'alert d-none';
        alertElement.textContent = '';
    }

    function showAlert(type, message) {
        if (!alertElement) {
            return;
        }

        if (hideAlertTimeout) {
            clearTimeout(hideAlertTimeout);
            hideAlertTimeout = null;
        }

        var finalType = typeof type === 'string' && type ? type : 'info';
        alertElement.className = 'alert alert-' + finalType;
        alertElement.textContent = String(message || '');

        if (alertElement.textContent === '') {
            alertElement.className = 'alert d-none';

            return;
        }

        hideAlertTimeout = setTimeout(function () {
            clearAlert();
        }, finalType === 'danger' ? 8000 : 4000);
    }

    function setLoading(state) {
        isLoading = Boolean(state);
        boardElement.classList.toggle('is-loading', isLoading);

        if (refreshButton) {
            refreshButton.disabled = isLoading || isUpdating;
            refreshButton.setAttribute('aria-busy', isLoading ? 'true' : 'false');
        }
    }

    function setUpdating(state) {
        isUpdating = Boolean(state);
        boardElement.classList.toggle('is-updating', isUpdating);

        if (refreshButton) {
            refreshButton.disabled = isLoading || isUpdating;
            refreshButton.setAttribute('aria-busy', isUpdating ? 'true' : 'false');
        }
    }

    function formatDate(value) {
        if (!value) {
            return '';
        }

        try {
            var date = new Date(value);

            if (Number.isNaN(date.getTime())) {
                return String(value);
            }

            return dateFormatter.format(date);
        } catch (error) {
            return String(value);
        }
    }

    function formatDateTime(value) {
        if (!value) {
            return '';
        }

        try {
            var date = new Date(value);

            if (Number.isNaN(date.getTime())) {
                return String(value);
            }

            return timeFormatter.format(date);
        } catch (error) {
            return String(value);
        }
    }

    function getSlaState(record) {
        var daysElapsed = Number(record && record.dias_transcurridos);

        if (!Number.isFinite(daysElapsed) || daysElapsed < 0) {
            daysElapsed = 0;
        }

        var hoursElapsed = daysElapsed * 24;

        if (slaWarningHours > 0 && hoursElapsed > slaWarningHours) {
            return 'breach';
        }

        if (slaTargetHours > 0 && hoursElapsed > slaTargetHours) {
            return 'warning';
        }

        return 'ok';
    }

    function createMetaRow(label, value) {
        if (!value) {
            return null;
        }

        var row = document.createElement('div');
        row.className = 'control-tower-meta-row';

        var labelElement = document.createElement('span');
        labelElement.className = 'control-tower-meta-label';
        labelElement.textContent = String(label);

        var valueElement = document.createElement('span');
        valueElement.className = 'control-tower-meta-value';
        valueElement.textContent = String(value);

        row.appendChild(labelElement);
        row.appendChild(valueElement);

        return row;
    }

    function createMetric(label, value) {
        var container = document.createElement('div');
        container.className = 'control-tower-metric';

        var labelElement = document.createElement('span');
        labelElement.className = 'control-tower-metric-label';
        labelElement.textContent = String(label);

        var valueElement = document.createElement('span');
        valueElement.className = 'control-tower-metric-value';
        valueElement.textContent = String(value);

        container.appendChild(labelElement);
        container.appendChild(valueElement);

        return container;
    }

    function attachCardDragEvents(card) {
        card.addEventListener('dragstart', function (event) {
            if (isUpdating || isLoading) {
                event.preventDefault();
                return;
            }

            dragContext = {
                recordId: Number(card.getAttribute('data-record-id')),
                statusId: Number(card.getAttribute('data-status-id'))
            };

            card.classList.add('is-dragging');

            if (event && event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', JSON.stringify(dragContext));
            }
        });

        card.addEventListener('dragend', function () {
            card.classList.remove('is-dragging');
            dragContext = null;
            clearDropzoneHighlight();
        });
    }

    function clearDropzoneHighlight() {
        dropzones.forEach(function (zone) {
            zone.classList.remove('is-over');
        });
    }

    function renderRecordCard(record) {
        var card = document.createElement('article');
        card.className = 'control-tower-card card shadow-sm';
        card.setAttribute('draggable', 'true');
        card.setAttribute('data-record-id', String(record.id || ''));
        card.setAttribute('data-status-id', String(record.status_id || ''));

        var body = document.createElement('div');
        body.className = 'card-body';
        card.appendChild(body);

        var header = document.createElement('div');
        header.className = 'd-flex justify-content-between align-items-start gap-2 mb-2';
        body.appendChild(header);

        var title = document.createElement('h3');
        title.className = 'h6 mb-0';
        title.textContent = record.referencia || ('#' + String(record.id || ''));
        header.appendChild(title);

        var slaState = getSlaState(record);
        var slaBadge = document.createElement('span');
        slaBadge.className = 'control-tower-sla badge rounded-pill';

        if (slaState === 'breach') {
            slaBadge.classList.add('text-bg-danger', 'control-tower-sla-breach');
            slaBadge.textContent = translate('control_tower.sla.breach', 'Outside SLA');
        } else if (slaState === 'warning') {
            slaBadge.classList.add('text-bg-warning', 'control-tower-sla-warning');
            slaBadge.textContent = translate('control_tower.sla.warning', 'Approaching SLA');
        } else {
            slaBadge.classList.add('text-bg-success', 'control-tower-sla-ok');
            slaBadge.textContent = translate('control_tower.sla.ok', 'Within SLA');
        }

        header.appendChild(slaBadge);

        var landingRow = createMetaRow(
            translate('control_tower.card.landing', 'Unloading'),
            record.fecha_desembarque_display || formatDate(record.fecha_desembarque)
        );

        if (landingRow) {
            body.appendChild(landingRow);
        }

        var departureRow = createMetaRow(
            translate('control_tower.card.departure', 'Departure'),
            record.fecha_embarque_display || formatDate(record.fecha_embarque)
        );

        if (departureRow) {
            body.appendChild(departureRow);
        }

        var clientRow = createMetaRow(
            translate('control_tower.card.client', 'Client'),
            record.cliente
        );

        if (clientRow) {
            body.appendChild(clientRow);
        }

        var vesselRow = createMetaRow(
            translate('control_tower.card.vessel', 'Vessel'),
            record.barco
        );

        if (vesselRow) {
            body.appendChild(vesselRow);
        }

        var destinationRow = createMetaRow(
            translate('control_tower.card.destination', 'Destination'),
            record.destino
        );

        if (destinationRow) {
            body.appendChild(destinationRow);
        }

        var noticeRow = createMetaRow(
            translate('control_tower.card.notice', 'Notice'),
            record.folio_aviso
        );

        if (noticeRow) {
            body.appendChild(noticeRow);
        }

        var metricsContainer = document.createElement('div');
        metricsContainer.className = 'control-tower-metrics';

        metricsContainer.appendChild(createMetric(
            translate('control_tower.card.days_elapsed', 'Days elapsed'),
            Number(record.dias_transcurridos || 0)
        ));

        metricsContainer.appendChild(createMetric(
            translate('control_tower.card.days_out', 'Dwell time (days)'),
            Number(record.dias_fuera || 0)
        ));

        body.appendChild(metricsContainer);

        if (record.created_at) {
            var createdMeta = document.createElement('div');
            createdMeta.className = 'control-tower-meta-row text-muted small';
            createdMeta.textContent = formatDateTime(record.created_at);
            body.appendChild(createdMeta);
        }

        attachCardDragEvents(card);

        return card;
    }

    function renderStatusColumn(statusId, records) {
        var column = columnsByStatus[statusId];

        if (!column) {
            return;
        }

        var dropzone = column.querySelector('.control-tower-dropzone');
        var countBadge = column.querySelector('[data-status-count]');

        if (!dropzone) {
            return;
        }

        dropzone.innerHTML = '';

        var list = document.createElement('div');
        list.className = 'control-tower-card-list';
        dropzone.appendChild(list);

        if (!Array.isArray(records) || records.length === 0) {
            var emptyState = document.createElement('p');
            emptyState.className = 'text-muted small mb-0 control-tower-empty';
            emptyState.textContent = emptyLabel;
            dropzone.appendChild(emptyState);
        } else {
            records.forEach(function (record) {
                list.appendChild(renderRecordCard(record));
            });
        }

        if (countBadge) {
            countBadge.textContent = String(Array.isArray(records) ? records.length : 0);
        }
    }

    function renderBoard(data) {
        var statusesData = Array.isArray(data && data.statuses) ? data.statuses : [];
        var statusMap = {};

        statusesData.forEach(function (statusPayload) {
            var id = Number(statusPayload && statusPayload.id);

            if (!Number.isFinite(id) || id <= 0) {
                return;
            }

            statusMap[id] = Array.isArray(statusPayload.records) ? statusPayload.records : [];
        });

        statuses.forEach(function (status) {
            var id = Number(status && status.id);

            if (!Number.isFinite(id) || id <= 0) {
                return;
            }

            renderStatusColumn(id, statusMap[id] || []);
        });

        if (lastRefreshedElement) {
            var generatedAt = data && data.summary ? data.summary.generated_at : '';
            var formatted = generatedAt ? formatDateTime(generatedAt) : '';
            lastRefreshedElement.textContent = formatted || '—';
        }
    }

    function handleUnauthorized() {
        showAlert('warning', translate('common.session_expired', 'Your session has expired.'));
    }

    function fetchBoardData() {
        if (isLoading || isUpdating) {
            return;
        }

        setLoading(true);

        fetch(endpoints.board || '../api/desembarques/board.php', {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'Accept': 'application/json'
            }
        })
            .then(function (response) {
                if (response.status === 401 || response.status === 419) {
                    handleUnauthorized();
                    return null;
                }

                if (response.status === 403) {
                    showAlert('warning', translate('control_tower.update.unauthorized', 'You do not have permission to update this status.'));
                    return null;
                }

                if (!response.ok) {
                    return response.text().then(function (text) {
                        throw new Error(text || response.statusText);
                    });
                }

                return response.json();
            })
            .then(function (payload) {
                if (!payload || typeof payload !== 'object') {
                    return;
                }

                if (payload.success === false) {
                    showAlert('danger', translate('control_tower.alert.load_error', 'Unable to load the board: {{error}}', {
                        error: payload.message || 'Unknown error'
                    }));
                    return;
                }

                renderBoard(payload);
                clearAlert();
            })
            .catch(function (error) {
                showAlert('danger', translate('control_tower.alert.load_error', 'Unable to load the board: {{error}}', {
                    error: error && error.message ? error.message : String(error)
                }));
            })
            .finally(function () {
                setLoading(false);
            });
    }

    function updateRecordStatus(recordId, statusId) {
        if (isUpdating || isLoading) {
            return;
        }

        if (!Number.isFinite(recordId) || recordId <= 0 || !Number.isFinite(statusId) || statusId <= 0) {
            return;
        }

        setUpdating(true);

        var shouldRefreshBoard = false;

        fetch(endpoints.updateStatus || '../api/desembarques/update_status.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                id: recordId,
                status_id: statusId,
                csrf_token: csrfToken
            })
        })
            .then(function (response) {
                if (response.status === 401 || response.status === 419) {
                    handleUnauthorized();
                    return null;
                }

                if (!response.ok) {
                    return response.json().catch(function () { return {}; }).then(function (payload) {
                        throw new Error(payload && payload.message ? payload.message : response.statusText);
                    });
                }

                return response.json();
            })
            .then(function (payload) {
                if (!payload) {
                    return;
                }

                if (payload.success === false) {
                    showAlert('danger', payload.message || translate('control_tower.update.error', 'Unable to update the status: {{error}}', { error: '' }));
                    return;
                }

                var message = payload.message || translate('control_tower.update.success', 'Status updated to {{status}}.', {
                    status: ''
                });
                showAlert('success', message);
                shouldRefreshBoard = true;
            })
            .catch(function (error) {
                showAlert('danger', translate('control_tower.update.error', 'Unable to update the status: {{error}}', {
                    error: error && error.message ? error.message : String(error)
                }));
                shouldRefreshBoard = true;
            })
            .finally(function () {
                setUpdating(false);

                if (shouldRefreshBoard) {
                    fetchBoardData();
                }
            });
    }

    dropzones.forEach(function (zone) {
        zone.addEventListener('dragover', function (event) {
            if (isUpdating || isLoading) {
                return;
            }

            event.preventDefault();
            zone.classList.add('is-over');
        });

        zone.addEventListener('dragenter', function (event) {
            if (isUpdating || isLoading) {
                return;
            }

            event.preventDefault();
            zone.classList.add('is-over');
        });

        zone.addEventListener('dragleave', function () {
            zone.classList.remove('is-over');
        });

        zone.addEventListener('drop', function (event) {
            event.preventDefault();
            zone.classList.remove('is-over');

            if (!dragContext) {
                try {
                    if (event && event.dataTransfer) {
                        var payload = event.dataTransfer.getData('text/plain');

                        if (payload) {
                            dragContext = JSON.parse(payload);
                        }
                    }
                } catch (error) {
                    dragContext = null;
                }
            }

            if (!dragContext) {
                return;
            }

            var targetStatusId = Number(zone.getAttribute('data-status-id'));

            if (!Number.isFinite(targetStatusId) || targetStatusId <= 0) {
                return;
            }

            if (dragContext.statusId === targetStatusId) {
                showAlert('info', translate('control_tower.update.no_change', 'The unloading record already has that status.'));
                return;
            }

            updateRecordStatus(Number(dragContext.recordId), targetStatusId);
        });
    });

    if (refreshButton) {
        refreshButton.addEventListener('click', function () {
            fetchBoardData();
        });
    }

    fetchBoardData();
})();
