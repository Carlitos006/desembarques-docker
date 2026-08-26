(function () {
    'use strict';

    var language = window.AppConfig && window.AppConfig.language === 'en' ? 'en' : 'es';
    function text(es, en) {
        return window.AppI18n && typeof window.AppI18n.text === 'function'
            ? window.AppI18n.text(es, en)
            : (language === 'en' ? en : es);
    }

    function setFeedback(form, type, message) {
        var node = form.querySelector('[data-soft-delete-feedback]');
        if (!node) return;
        node.className = 'alert mt-3 mb-0 alert-' + type;
        node.textContent = message || '';
    }

    function setBusy(form, busy) {
        var button = form.querySelector('button[type="submit"]');
        if (!button) return;
        if (!button.dataset.defaultLabel) button.dataset.defaultLabel = button.textContent;
        button.disabled = busy;
        button.textContent = busy ? text('Procesando…', 'Processing…') : button.dataset.defaultLabel;
    }

    function prepareRestoreModal(button) {
        var form = document.querySelector('[data-soft-delete-form="restore"]');
        if (!form) return;
        var id = String(button.dataset.desembarqueId || '');
        var notice = String(button.dataset.noticeNumber || '');
        form.reset();
        form.querySelector('[name="desembarque_id"]').value = id;
        form.dataset.noticeNumber = notice;
        var example = form.querySelector('[data-soft-delete-confirmation-example]');
        if (example) example.textContent = (language === 'en' ? 'RESTORE ' : 'RESTAURAR ') + notice;
        var feedback = form.querySelector('[data-soft-delete-feedback]');
        if (feedback) feedback.className = 'alert d-none mt-3 mb-0';
    }

    document.addEventListener('click', function (event) {
        var restoreButton = event.target.closest('[data-soft-delete-restore]');
        if (restoreButton) prepareRestoreModal(restoreButton);
    });

    document.querySelectorAll('[data-soft-delete-form]').forEach(function (form) {
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            var action = form.dataset.softDeleteForm;
            if (action !== 'delete' && action !== 'restore') {
                setFeedback(form, 'danger', text('La acción administrativa no es válida.', 'The administrative action is not valid.'));
                return;
            }
            var config = window.AvisoSoftDeleteConfig || {};
            var expedienteConfig = window.AppConfig && window.AppConfig.avisoExpediente
                ? window.AppConfig.avisoExpediente
                : {};
            var defaultEndpoint = action === 'delete'
                ? '../api/desembarques/admin/soft_delete.php'
                : '../api/desembarques/admin/restore.php';
            var endpoint = action === 'delete'
                ? (config.deleteEndpoint || defaultEndpoint)
                : (config.restoreEndpoint || defaultEndpoint);
            var formData = new FormData(form);
            var payload = {
                csrf_token: String(formData.get('csrf_token') || config.csrfToken || expedienteConfig.csrfToken || ''),
                desembarque_id: String(formData.get('desembarque_id') || ''),
                reason: String(formData.get('reason') || '').trim(),
                confirmation: String(formData.get('confirmation') || '').trim()
            };
            var notice = String(form.dataset.noticeNumber || '');
            var expected = (action === 'delete'
                ? (language === 'en' ? 'DELETE ' : 'ELIMINAR ')
                : (language === 'en' ? 'RESTORE ' : 'RESTAURAR ')) + notice;
            if (!payload.reason) {
                setFeedback(form, 'warning', text('El motivo es obligatorio.', 'A reason is required.'));
                return;
            }
            if (payload.confirmation !== expected) {
                setFeedback(form, 'warning', text('Escribe exactamente: ', 'Type exactly: ') + expected);
                return;
            }

            setBusy(form, true);
            setFeedback(form, 'info', text('Procesando la solicitud administrativa…', 'Processing the administrative request…'));
            try {
                var response = await fetch(endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json', 'X-CSRF-Token': payload.csrf_token},
                    body: JSON.stringify(payload)
                });
                var data = await response.json().catch(function () { return {}; });
                if (!response.ok || data.success === false) {
                    var confirmationError = data.errors && data.errors.confirmation
                        ? String(data.errors.confirmation)
                        : '';
                    throw new Error(confirmationError || data.message || text('No fue posible completar la acción.', 'The action could not be completed.'));
                }
                setFeedback(form, 'success', data.message || text('Acción completada.', 'Action completed.'));
                var redirect = action === 'delete' ? config.deleteRedirect : config.restoreRedirect;
                window.setTimeout(function () {
                    window.location.href = redirect || data.redirect_url || 'reportes.php';
                }, 500);
            } catch (error) {
                setFeedback(form, 'danger', error && error.message ? error.message : text('No fue posible completar la acción.', 'The action could not be completed.'));
                setBusy(form, false);
            }
        });
    });
})();
