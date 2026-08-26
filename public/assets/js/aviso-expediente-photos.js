(function () {
    'use strict';

    var root = window.AppConfig && window.AppConfig.avisoExpediente
        ? window.AppConfig.avisoExpediente
        : {};
    var config = root.photos || {};
    var form = document.getElementById('photo-upload-form');
    var input = document.getElementById('photo-upload-files');
    var list = document.getElementById('photo-upload-list');
    var feedback = document.getElementById('photo-upload-feedback');
    var submit = document.getElementById('photo-upload-submit');
    var modalElement = document.getElementById('photoUploadModal');
    var maxFiles = Number(config.maxFiles || 20);
    var maxFileSizeMb = Number(config.maxFileSizeMb || 25);
    var maxFileSize = maxFileSizeMb * 1024 * 1024;
    var items = Array.isArray(config.items) ? config.items : [];
    var deleteEndpoint = String(config.deleteEndpoint || '../api/desembarques/aviso/photo_delete.php');
    var language = window.AppConfig && window.AppConfig.language === 'en' ? 'en' : 'es';

    function text(es, en) {
        return window.AppI18n && typeof window.AppI18n.text === 'function'
            ? window.AppI18n.text(es, en)
            : (language === 'en' ? en : es);
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatBytes(bytes) {
        var size = Number(bytes || 0);
        if (!Number.isFinite(size) || size <= 0) return '0 B';
        var units = ['B', 'KB', 'MB', 'GB'];
        var index = 0;
        while (size >= 1024 && index < units.length - 1) {
            size /= 1024;
            index += 1;
        }
        return (size >= 10 || index === 0 ? size.toFixed(0) : size.toFixed(1)) + ' ' + units[index];
    }

    function setFeedback(type, message) {
        if (!feedback) return;
        feedback.className = 'alert alert-' + type + ' mt-3 mb-0';
        feedback.textContent = message || '';
        feedback.classList.toggle('d-none', !message);
    }

    function itemOptions(selected) {
        var html = '<option value="">' + escapeHtml(text('General / sin asignar', 'General / unassigned')) + '</option>';
        items.forEach(function (item) {
            var id = Number(item.id || 0);
            if (!id) return;
            var label = String(item.descripcion || item.description || (text('Mercancía #', 'Merchandise line #') + id)).trim();
            if (label.length > 90) label = label.slice(0, 87) + '…';
            html += '<option value="' + id + '" ' + (Number(selected || 0) === id ? 'selected' : '') + '>'
                + escapeHtml(label) + '</option>';
        });
        return html;
    }

    function deletePhoto(button) {
        var photoId = String(button.dataset.photoId || '');
        var photoName = String(button.dataset.photoName || text('esta fotografía', 'this photo')).trim() || text('esta fotografía', 'this photo');
        var csrfToken = String(button.dataset.csrfToken || root.csrfToken || '');
        var desembarqueId = String(button.dataset.desembarqueId || root.desembarqueId || '');
        if (!photoId || !desembarqueId || !deleteEndpoint) {
            window.alert(text('La fotografía indicada no es válida.', 'The selected photo is not valid.'));
            return;
        }
        if (!csrfToken) {
            window.alert(text('El token de seguridad no está disponible. Recarga la página e inténtalo de nuevo.', 'The security token is not available. Reload the page and try again.'));
            return;
        }

        var confirmed = window.confirm(
            text('¿Eliminar "', 'Delete "') + photoName + text('" del expediente?\n\n', '" from the case file?\n\n')
            + text('La fotografía nativa se eliminará. Los PDFs históricos o ya emitidos no serán modificados.', 'The native photo will be deleted. Historical or previously issued PDFs will not be modified.')
        );
        if (!confirmed) return;

        var originalLabel = button.textContent;
        button.disabled = true;
        button.textContent = text('Eliminando…', 'Deleting…');

        var body = new FormData();
        body.append('csrf_token', csrfToken);
        body.append('desembarque_id', desembarqueId);
        body.append('photo_id', photoId);

        fetch(deleteEndpoint, {
            method: 'POST',
            credentials: 'include',
            body: body,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrfToken }
        })
            .then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (data) {
                    if (!response.ok || !data.success) {
                        throw new Error(data.message || text('No fue posible eliminar la fotografía.', 'The photo could not be deleted.'));
                    }
                    return data;
                });
            })
            .then(function () {
                var redirectUrl = new URL(window.location.href);
                redirectUrl.searchParams.set('id', desembarqueId);
                redirectUrl.searchParams.set('tab', 'fotos');
                redirectUrl.searchParams.set('photo_deleted', '1');
                redirectUrl.searchParams.delete('photos_uploaded');
                window.location.assign(redirectUrl.toString());
            })
            .catch(function (error) {
                window.alert(error && error.message ? error.message : text('No fue posible eliminar la fotografía.', 'The photo could not be deleted.'));
                button.disabled = false;
                button.textContent = originalLabel;
            });
    }

    document.addEventListener('click', function (event) {
        var target = event.target instanceof Element ? event.target : null;
        var button = target ? target.closest('[data-photo-delete]') : null;
        if (button) deletePhoto(button);
    });

    // La eliminación es independiente de la inicialización del formulario de carga.
    if (!form || !config.enabled || !input || !list || !submit) {
        return;
    }

    function renderFiles() {
        if (!list || !input) return;
        var files = Array.from(input.files || []);
        if (!files.length) {
            list.innerHTML = '<div class="text-body-secondary small py-2">' + escapeHtml(text('Selecciona JPG, PNG, GIF o WEBP.', 'Select JPG, PNG, GIF, or WEBP files.')) + '</div>';
            return;
        }

        list.innerHTML = files.map(function (file, index) {
            var tooLarge = file.size > maxFileSize;
            var caption = file.name.replace(/\.[^.]+$/, '');
            return '<article class="border rounded-3 p-3 mb-2" data-photo-upload-row data-index="' + index + '">'
                + '<div class="d-flex flex-column flex-lg-row justify-content-between gap-2 mb-3">'
                + '<div><strong class="small">' + escapeHtml(file.name) + '</strong>'
                + '<div class="small ' + (tooLarge ? 'text-danger fw-semibold' : 'text-body-secondary') + '">'
                + escapeHtml(formatBytes(file.size)) + (tooLarge ? escapeHtml(text(' · supera ', ' · exceeds ')) + maxFileSizeMb + ' MB' : '') + '</div></div>'
                + '<span class="badge text-bg-light border text-dark align-self-start">' + escapeHtml(text('Foto ', 'Photo ')) + (index + 1) + '</span>'
                + '</div>'
                + '<div class="row g-2">'
                + '<div class="col-lg-5"><label class="form-label small fw-semibold">' + escapeHtml(text('Mercancía asociada', 'Associated merchandise line')) + '</label>'
                + '<select class="form-select form-select-sm" data-photo-item>' + itemOptions(null) + '</select></div>'
                + '<div class="col-lg-7"><label class="form-label small fw-semibold">' + escapeHtml(text('Pie de foto', 'Photo caption')) + '</label>'
                + '<input class="form-control form-control-sm" maxlength="255" data-photo-caption value="' + escapeHtml(caption) + '"></div>'
                + '</div></article>';
        }).join('');
    }

    input.addEventListener('change', function () {
        setFeedback('', '');
        renderFiles();
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        var files = Array.from(input && input.files ? input.files : []);
        if (!files.length) {
            setFeedback('warning', text('Selecciona al menos una fotografía.', 'Select at least one photo.'));
            return;
        }
        if (files.length > maxFiles) {
            setFeedback('warning', text('Puedes cargar hasta ', 'You can upload up to ') + maxFiles + text(' fotografías por operación.', ' photos per operation.'));
            return;
        }
        var oversized = files.find(function (file) { return file.size > maxFileSize; });
        if (oversized) {
            setFeedback('warning', oversized.name + text(' pesa ', ' is ') + formatBytes(oversized.size) + text('. El máximo es ', '. The maximum is ') + maxFileSizeMb + text(' MB por foto.', ' MB per photo.'));
            return;
        }

        var rows = Array.from(list.querySelectorAll('[data-photo-upload-row]'));
        var metadata = rows.map(function (row) {
            var itemValue = row.querySelector('[data-photo-item]')?.value || '';
            return {
                aviso_item_id: itemValue ? Number(itemValue) : null,
                caption: (row.querySelector('[data-photo-caption]')?.value || '').trim() || null
            };
        });

        var body = new FormData();
        var formCsrf = form.querySelector('[name="csrf_token"]');
        var formDesembarqueId = form.querySelector('[name="desembarque_id"]');
        var desembarqueId = String((formDesembarqueId && formDesembarqueId.value) || root.desembarqueId || '');
        body.append('csrf_token', String((formCsrf && formCsrf.value) || root.csrfToken || ''));
        body.append('desembarque_id', desembarqueId);
        body.append('photo_metadata', JSON.stringify(metadata));
        files.forEach(function (file) { body.append('photos[]', file, file.name); });

        submit.disabled = true;
        var original = submit.innerHTML;
        submit.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>' + escapeHtml(text('Guardando…', 'Saving…'));
        setFeedback('info', text('Guardando fotografías en el expediente…', 'Saving photos to the case file…'));

        fetch(config.uploadEndpoint, {
            method: 'POST',
            credentials: 'include',
            body: body,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (data) {
                    if (!response.ok || !data.success) {
                        throw new Error(data.message || data.detail || text('No fue posible guardar las fotografías.', 'The photos could not be saved.'));
                    }
                    return data;
                });
            })
            .then(function (data) {
                var count = Number(data.photos_count || files.length || 0);
                var redirectUrl = new URL(window.location.href);
                redirectUrl.searchParams.set('id', desembarqueId);
                redirectUrl.searchParams.set('tab', 'fotos');
                redirectUrl.searchParams.set('photos_uploaded', String(count));
                redirectUrl.searchParams.delete('photo_deleted');
                window.location.assign(redirectUrl.toString());
            })
            .catch(function (error) {
                setFeedback('danger', error && error.message ? error.message : text('No fue posible guardar las fotografías.', 'The photos could not be saved.'));
                submit.disabled = false;
                submit.innerHTML = original;
            });
    });

    if (modalElement) {
        modalElement.addEventListener('hidden.bs.modal', function () {
            form.reset();
            setFeedback('', '');
            renderFiles();
        });
    }

    renderFiles();
}());
