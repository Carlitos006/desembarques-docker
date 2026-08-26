(function () {
    'use strict';

    var appConfig = window.AppConfig && window.AppConfig.avisoExpediente
        ? window.AppConfig.avisoExpediente
        : {};
    var language = window.AppConfig && window.AppConfig.language === 'en' ? 'en' : 'es';
    var form = document.getElementById('aviso-status-form');

    if (!form || !appConfig.statusEndpoint) {
        return;
    }

    var target = document.getElementById('aviso-status-target');
    var effectiveWrap = document.getElementById('aviso-status-effective-wrap');
    var effectiveInput = document.getElementById('aviso-status-effective');
    var reasonInput = document.getElementById('aviso-status-reason');
    var reasonHelp = document.getElementById('aviso-status-reason-help');
    var feedback = document.getElementById('aviso-status-feedback');
    var submit = document.getElementById('aviso-status-submit');

    function text(es, en) {
        return language === 'en' ? en : es;
    }

    function setFeedback(type, message) {
        if (!feedback) {
            return;
        }
        feedback.className = 'alert mt-3 mb-0 alert-' + type;
        feedback.textContent = message || '';
        feedback.classList.toggle('d-none', !message);
    }

    function pad(value) {
        return String(value).padStart(2, '0');
    }

    function localDateTimeValue(date) {
        return date.getFullYear()
            + '-' + pad(date.getMonth() + 1)
            + '-' + pad(date.getDate())
            + 'T' + pad(date.getHours())
            + ':' + pad(date.getMinutes());
    }

    function selectedOption() {
        return target && target.selectedOptions && target.selectedOptions.length
            ? target.selectedOptions[0]
            : null;
    }

    function updateRequirements() {
        var option = selectedOption();
        var requiresReason = option && option.dataset.requiresReason === '1';
        var requiresEffective = option && option.dataset.requiresEffective === '1';

        if (effectiveWrap && effectiveInput) {
            effectiveWrap.classList.toggle('d-none', !requiresEffective);
            effectiveInput.required = !!requiresEffective;
            if (requiresEffective && !effectiveInput.value) {
                effectiveInput.value = localDateTimeValue(new Date());
            }
            if (!requiresEffective) {
                effectiveInput.value = '';
            }
        }

        if (reasonInput) {
            reasonInput.required = !!requiresReason;
        }
        if (reasonHelp) {
            reasonHelp.textContent = requiresReason
                ? text('Este cambio requiere un motivo.', 'A reason is required for this transition.')
                : text('La observación es opcional para este cambio.', 'The note is optional for this transition.');
        }
        setFeedback('info', '');
    }

    if (target) {
        target.addEventListener('change', updateRequirements);
        updateRequirements();
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        setFeedback('info', '');

        var toStatus = target ? String(target.value || '').trim() : '';
        if (!toStatus) {
            setFeedback('warning', text('Selecciona el nuevo estado.', 'Select the new status.'));
            return;
        }

        if (!form.reportValidity()) {
            return;
        }

        var payload = {
            csrf_token: String(appConfig.csrfToken || ''),
            desembarque_id: String(appConfig.desembarqueId || ''),
            to_status: toStatus,
            reason: reasonInput ? String(reasonInput.value || '').trim() : '',
            effective_at: effectiveInput && effectiveInput.value
                ? String(effectiveInput.value).replace('T', ' ') + ':00'
                : ''
        };

        if (submit) {
            submit.disabled = true;
            submit.dataset.originalText = submit.dataset.originalText || submit.textContent;
            submit.textContent = text('Aplicando…', 'Applying…');
        }

        fetch(appConfig.statusEndpoint, {
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
                        : text('No fue posible actualizar el estado.', 'Unable to update the status.'));
                }
                return data;
            });
        }).then(function () {
            setFeedback('success', text('Estado actualizado. Recargando expediente…', 'Status updated. Reloading case file…'));
            window.setTimeout(function () {
                window.location.reload();
            }, 450);
        }).catch(function (error) {
            setFeedback('danger', error && error.message
                ? error.message
                : text('No fue posible actualizar el estado.', 'Unable to update the status.'));
        }).finally(function () {
            if (submit) {
                submit.disabled = false;
                submit.textContent = submit.dataset.originalText || text('Aplicar estado', 'Apply status');
            }
        });
    });
})();
