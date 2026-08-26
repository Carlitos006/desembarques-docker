(function ($) {
    'use strict';

    var appConfig = window.AppConfig || {};
    var translations = appConfig.translations || {};
    var csrfToken = String(appConfig.csrfToken || '');
    var routes = appConfig.routes || {};

    function translate(key, fallback) {
        if (Object.prototype.hasOwnProperty.call(translations, key)) {
            return translations[key];
        }

        return fallback;
    }

    function stringValue(value) {
        if (value === undefined || value === null) {
            return '';
        }

        return String(value);
    }

    function resetAlert($alert) {
        if ($alert.length) {
            $alert.removeClass('alert-success alert-danger alert-warning alert-info alert-primary').addClass('d-none');
            $alert.text('');
        }
    }

    function showAlert($alert, type, message) {
        if (! $alert.length) {
            return;
        }

        $alert
            .removeClass('d-none alert-success alert-danger alert-warning alert-info alert-primary')
            .addClass('alert-' + type)
            .text(message || '');
    }

    function clearFieldErrors($form) {
        if (! $form.length) {
            return;
        }

        $form.find('.is-invalid').each(function () {
            var $field = $(this);
            $field.removeClass('is-invalid');
            $field.removeAttr('aria-invalid');
        });

        $form.find('.invalid-feedback[data-feedback-for]').text('');
    }

    function applyFieldErrors($form, errors) {
        if (! $form.length || ! errors) {
            return;
        }

        Object.keys(errors).forEach(function (field) {
            var message = errors[field];
            var $field = $form.find('[name="' + field + '"]');
            var $feedback = $form.find('.invalid-feedback[data-feedback-for="' + field + '"]');

            if ($field.length) {
                $field.addClass('is-invalid');
                $field.attr('aria-invalid', 'true');
            }

            if ($feedback.length) {
                $feedback.text(message || '');
            }
        });
    }

    function insertStatusRow($tableBody, $row) {
        if (! $tableBody.length || ! $row) {
            return;
        }

        var $emptyRow = $tableBody.find('.statuses-empty-row');

        if ($emptyRow.length) {
            $emptyRow.remove();
        }

        if ($tableBody.children().length) {
            $tableBody.prepend($row);
        } else {
            $tableBody.append($row);
        }
    }

    function createStatusRow(status, options) {
        options = options || {};

        if (! status) {
            return null;
        }

        var id = stringValue(status.id).trim();

        if (! id) {
            return null;
        }

        var slug = stringValue(status.slug);
        var nameEs = stringValue(status.name_es);
        var nameEn = stringValue(status.name_en);
        var createdAt = stringValue(status.created_at_display || status.created_at);
        var isActive = Boolean(status.is_active);
        var isDefault = Boolean(status.is_default);

        var editLabel = stringValue(options.editLabel || translate('statuses.table.actions.edit', 'Editar'));
        var toggleActiveLabel = stringValue(options.toggleActiveLabel || translate('statuses.table.toggle_active', 'Cambiar estado activo'));
        var toggleDefaultLabel = stringValue(options.toggleDefaultLabel || translate('statuses.table.toggle_default', 'Cambiar estado predeterminado'));

        var $row = $('<tr></tr>')
            .attr('data-status-id', id)
            .attr('data-status-slug', slug)
            .attr('data-status-name-es', nameEs)
            .attr('data-status-name-en', nameEn)
            .attr('data-status-active', isActive ? '1' : '0')
            .attr('data-status-default', isDefault ? '1' : '0');

        $row.append($('<td></td>').text(id));
        $row.append($('<td></td>').text(slug));
        $row.append($('<td></td>').text(nameEs));
        $row.append($('<td></td>').text(nameEn));

        var $activeToggle = $('<input type="checkbox" class="form-check-input status-active-toggle" role="switch">')
            .attr('data-status-id', id)
            .attr('aria-label', toggleActiveLabel)
            .attr('title', toggleActiveLabel)
            .prop('checked', isActive);

        var $activeCell = $('<td class="text-center"></td>');
        var $activeWrapper = $('<div class="form-check form-switch justify-content-center d-flex"></div>');
        $activeWrapper.append($activeToggle);
        $activeCell.append($activeWrapper);
        $row.append($activeCell);

        var $defaultToggle = $('<input type="checkbox" class="form-check-input status-default-toggle" role="switch">')
            .attr('data-status-id', id)
            .attr('aria-label', toggleDefaultLabel)
            .attr('title', toggleDefaultLabel)
            .prop('checked', isDefault);

        if (! isActive) {
            $defaultToggle.prop('disabled', true);
        }

        var $defaultCell = $('<td class="text-center"></td>');
        var $defaultWrapper = $('<div class="form-check form-switch justify-content-center d-flex"></div>');
        $defaultWrapper.append($defaultToggle);
        $defaultCell.append($defaultWrapper);
        $row.append($defaultCell);

        $row.append($('<td></td>').text(createdAt));

        var $actionsCell = $('<td class="text-end"></td>');
        var $editButton = $('<button type="button" class="btn btn-outline-primary btn-sm js-edit-status"></button>')
            .attr('data-status-id', id)
            .attr('data-status-slug', slug)
            .attr('data-status-name-es', nameEs)
            .attr('data-status-name-en', nameEn)
            .text(editLabel);

        $actionsCell.append($editButton);
        $row.append($actionsCell);

        return $row;
    }

    function upsertStatusRow($tableBody, status, options) {
        if (! $tableBody.length || ! status) {
            return;
        }

        var id = stringValue(status.id).trim();

        if (! id) {
            return;
        }

        var selector = 'tr[data-status-id="' + id + '"]';
        var $existingRow = $tableBody.find(selector);
        var $row = createStatusRow(status, options);

        if (! $row) {
            return;
        }

        if ($existingRow.length) {
            $existingRow.replaceWith($row);
            return;
        }

        insertStatusRow($tableBody, $row);
    }

    function syncDefaultToggles($tableBody, defaultId) {
        if (! $tableBody.length) {
            return;
        }

        $tableBody.find('.status-default-toggle').each(function () {
            var $toggle = $(this);
            var statusId = $toggle.attr('data-status-id') || '';
            var $row = $toggle.closest('tr');
            var isActive = $row.attr('data-status-active') === '1';
            var isDefault = defaultId !== null && statusId === defaultId;

            $toggle.prop('checked', isDefault);
            $row.attr('data-status-default', isDefault ? '1' : '0');

            if (isActive) {
                $toggle.prop('disabled', false);
            } else {
                $toggle.prop('disabled', true);
            }
        });
    }

    $(function () {
        var $form = $('#status-form');
        var $alert = $('#statuses-alert');
        var $tableBody = $('#statuses-table-body');

        if (! $form.length) {
            return;
        }

        var $statusIdField = $('#status-id');
        var $slugField = $('#status-slug');
        var $nameEsField = $('#status-name-es');
        var $nameEnField = $('#status-name-en');
        var $submitButton = $form.find('button[type="submit"]');

        var editButtonLabel = translate('statuses.table.actions.edit', 'Editar');
        var createButtonLabel = translate('statuses.form.submit', 'Crear estado');
        var updateButtonLabel = translate('statuses.form.update', 'Actualizar estado');
        var toggleActiveLabel = translate('statuses.table.toggle_active', 'Cambiar estado activo');
        var toggleDefaultLabel = translate('statuses.table.toggle_default', 'Cambiar estado predeterminado');

        var rowOptions = {
            editLabel: editButtonLabel,
            toggleActiveLabel: toggleActiveLabel,
            toggleDefaultLabel: toggleDefaultLabel
        };

        function setFormMode(mode, status) {
            if (mode === 'edit' && status) {
                var id = stringValue(status.id).trim();
                var slug = stringValue(status.slug);
                var nameEs = stringValue(status.name_es);
                var nameEn = stringValue(status.name_en);

                $statusIdField.val(id);
                $slugField.val(slug);
                $nameEsField.val(nameEs);
                $nameEnField.val(nameEn);

                if ($submitButton.length) {
                    $submitButton.text(updateButtonLabel);
                }

                return;
            }

            $statusIdField.val('');
            $slugField.val('');
            $nameEsField.val('');
            $nameEnField.val('');

            if ($submitButton.length) {
                $submitButton.text(createButtonLabel);
            }
        }

        setFormMode('create');

        $form.on('submit', function (event) {
            event.preventDefault();

            var mode = $statusIdField.val().trim() !== '' ? 'edit' : 'create';
            var requestUrl = mode === 'edit'
                ? (routes.update || '../api/statuses/update.php')
                : (routes.store || '../api/statuses/store.php');

            resetAlert($alert);
            clearFieldErrors($form);

            if ($submitButton.length) {
                $submitButton.prop('disabled', true);
            }

            $.ajax({
                url: requestUrl,
                method: 'POST',
                data: $form.serialize(),
                dataType: 'json',
            })
                .done(function (response) {
                    if (response && response.success && response.status) {
                        var message = response.message || (mode === 'edit'
                            ? translate('statuses.alert.update_success', 'El estado se actualizó correctamente.')
                            : translate('statuses.alert.success', 'El estado se registró correctamente.'));

                        showAlert($alert, 'success', message);
                        upsertStatusRow($tableBody, response.status, rowOptions);

                        if (response.default_status_id !== undefined) {
                            var defaultIdValue = response.default_status_id === null
                                ? null
                                : String(response.default_status_id);
                            syncDefaultToggles($tableBody, defaultIdValue);
                        }

                        if ($form[0]) {
                            $form[0].reset();
                        }

                        clearFieldErrors($form);
                        setFormMode('create');

                        if ($slugField.length) {
                            $slugField.trigger('focus');
                        }

                        return;
                    }

                    var fallback = translate('statuses.alert.validation', 'No fue posible guardar el estado con la información proporcionada.');
                    var messageText = response && response.message ? response.message : fallback;
                    showAlert($alert, 'warning', messageText);
                })
                .fail(function (jqXHR) {
                    var message = translate('statuses.alert.error', 'Ocurrió un error al guardar el estado.');
                    var type = 'danger';

                    if (jqXHR && jqXHR.responseJSON) {
                        if (jqXHR.responseJSON.message) {
                            message = jqXHR.responseJSON.message;
                        }

                        if (jqXHR.status === 422 && jqXHR.responseJSON.errors) {
                            applyFieldErrors($form, jqXHR.responseJSON.errors);
                            message = jqXHR.responseJSON.message || translate('statuses.alert.validation', 'No fue posible guardar el estado con la información proporcionada.');
                            type = 'warning';
                        }
                    }

                    if (jqXHR && jqXHR.status === 401) {
                        message = translate('common.session_expired', 'Tu sesión ha expirado. Por favor, inicia sesión nuevamente.');
                        type = 'warning';
                    } else if (jqXHR && jqXHR.status === 419) {
                        message = jqXHR.responseJSON && jqXHR.responseJSON.message
                            ? jqXHR.responseJSON.message
                            : translate('common.csrf_token_invalid', 'La seguridad del formulario expiró. Actualiza la página e inténtalo de nuevo.');
                        type = 'warning';
                    }

                    showAlert($alert, type, message);
                })
                .always(function () {
                    if ($submitButton.length) {
                        $submitButton.prop('disabled', false);
                    }
                });
        });

        $form.on('reset', function () {
            window.setTimeout(function () {
                clearFieldErrors($form);
                resetAlert($alert);
                setFormMode('create');
            }, 0);
        });

        $tableBody.on('click', '.js-edit-status', function () {
            var $button = $(this);
            var status = {
                id: $button.attr('data-status-id') || '',
                slug: $button.attr('data-status-slug') || '',
                name_es: $button.attr('data-status-name-es') || '',
                name_en: $button.attr('data-status-name-en') || '',
            };

            setFormMode('edit', status);

            if ($slugField.length) {
                $slugField.trigger('focus');
            }
        });

        function handleToggle($checkbox, field) {
            if (! $checkbox.length) {
                return;
            }

            if ($checkbox.data('updating')) {
                $checkbox.data('updating', false);
                return;
            }

            var statusId = $checkbox.attr('data-status-id') || '';

            if (! statusId) {
                return;
            }

            var isChecked = $checkbox.is(':checked');
            var previousValue = ! isChecked;

            var payload = {
                id: statusId,
                field: field,
                value: isChecked ? '1' : '0',
                csrf_token: csrfToken,
            };

            $checkbox.prop('disabled', true);

            $.ajax({
                url: routes.toggle || '../api/statuses/toggle.php',
                method: 'POST',
                data: payload,
                dataType: 'json',
            })
                .done(function (response) {
                    if (response && response.success && response.status) {
                        var message = response.message || translate('statuses.alert.update_success', 'El estado se actualizó correctamente.');
                        showAlert($alert, 'success', message);

                        upsertStatusRow($tableBody, response.status, rowOptions);

                        if (response.default_status_id !== undefined) {
                            var defaultIdValue = response.default_status_id === null
                                ? null
                                : String(response.default_status_id);
                            syncDefaultToggles($tableBody, defaultIdValue);
                        }

                        return;
                    }

                    var fallback = translate('statuses.alert.toggle_error', 'No fue posible actualizar el estado seleccionado.');
                    var messageText = response && response.message ? response.message : fallback;
                    showAlert($alert, 'warning', messageText);
                    revert();
                })
                .fail(function (jqXHR) {
                    var message = translate('statuses.alert.toggle_error', 'No fue posible actualizar el estado seleccionado.');
                    var type = 'danger';

                    if (jqXHR && jqXHR.responseJSON) {
                        if (jqXHR.responseJSON.message) {
                            message = jqXHR.responseJSON.message;
                        }

                        if (jqXHR.status === 422 && jqXHR.responseJSON.errors) {
                            var details = Object.values(jqXHR.responseJSON.errors).join(' ');

                            if (details) {
                                message += ' ' + details;
                            }

                            type = 'warning';
                        }
                    }

                    if (jqXHR && jqXHR.status === 401) {
                        message = translate('common.session_expired', 'Tu sesión ha expirado. Por favor, inicia sesión nuevamente.');
                        type = 'warning';
                    } else if (jqXHR && jqXHR.status === 419) {
                        message = jqXHR.responseJSON && jqXHR.responseJSON.message
                            ? jqXHR.responseJSON.message
                            : translate('common.csrf_token_invalid', 'La seguridad del formulario expiró. Actualiza la página e inténtalo de nuevo.');
                        type = 'warning';
                    }

                    showAlert($alert, type, message);
                    revert();
                })
                .always(function () {
                    if ($checkbox.closest('tr').length === 0) {
                        return;
                    }

                    $checkbox.prop('disabled', false);
                });

            function revert() {
                $checkbox.data('updating', true);
                $checkbox.prop('checked', previousValue);
                window.setTimeout(function () {
                    $checkbox.data('updating', false);
                }, 0);
            }
        }

        $tableBody.on('change', '.status-active-toggle', function () {
            handleToggle($(this), 'is_active');
        });

        $tableBody.on('change', '.status-default-toggle', function () {
            handleToggle($(this), 'is_default');
        });
    });
})(jQuery);
