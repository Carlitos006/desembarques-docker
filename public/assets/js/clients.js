(function ($) {
    'use strict';

    var appConfig = window.AppConfig || {};
    var translations = appConfig.translations || {};
    var canEditClients = Boolean(appConfig.canEditClients);
    var editButtonLabel = null;
    var updateSubmitLabel = null;
    var createSubmitLabel = null;

    function translate(key, fallback) {
        if (Object.prototype.hasOwnProperty.call(translations, key)) {
            return translations[key];
        }

        return fallback;
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

    function renderClientRow(client) {
        if (! client) {
            return null;
        }

        var idValue = client.id !== undefined && client.id !== null ? String(client.id) : '';
        var nameValue = client.name || '';
        var emailValue = client.email || '';
        var createdAtValue = client.created_at_display || client.created_at || '';

        var $row = $('<tr></tr>');

        if (idValue !== '') {
            $row.attr('data-client-id', idValue);
        }

        $row.attr('data-client-name', nameValue);
        $row.attr('data-client-email', emailValue);

        $row.append($('<td></td>').text(idValue));
        $row.append($('<td></td>').text(nameValue));
        $row.append($('<td></td>').text(emailValue));
        $row.append($('<td></td>').text(createdAtValue));

        if (canEditClients) {
            var label = editButtonLabel || translate('clients.table.actions.edit', 'Editar');
            var $actionsCell = $('<td></td>');
            var $editButton = $('<button type="button" class="btn btn-outline-primary btn-sm client-edit-button"></button>');
            $editButton.text(label);
            $editButton.attr('data-client-id', idValue);
            $editButton.attr('data-client-name', nameValue);
            $editButton.attr('data-client-email', emailValue);
            $actionsCell.append($editButton);
            $row.append($actionsCell);
        }

        return $row;
    }

    function insertClientRow($tableBody, $row) {
        if (! $tableBody.length || ! $row) {
            return;
        }

        var $emptyRow = $tableBody.find('.clients-empty-row');

        if ($emptyRow.length) {
            $emptyRow.remove();
        }

        if ($tableBody.children().length) {
            $tableBody.prepend($row);
        } else {
            $tableBody.append($row);
        }
    }

    function upsertClientRow($tableBody, client) {
        if (! $tableBody.length || ! client) {
            return;
        }

        var idValue = client.id !== undefined && client.id !== null ? String(client.id) : '';
        var selector = idValue !== '' ? 'tr[data-client-id="' + idValue + '"]' : '';
        var $existingRow = selector ? $tableBody.find(selector) : $();
        var $row = renderClientRow(client);

        if (! $row) {
            return;
        }

        if ($existingRow.length) {
            $existingRow.replaceWith($row);
            return;
        }

        insertClientRow($tableBody, $row);
    }

    $(function () {
        var $form = $('#client-form');
        var $alert = $('#clients-alert');
        var $tableBody = $('#clients-table-body');
        var $clientId = $('#client-id');
        var $nameInput = $('#client-name');
        var $emailInput = $('#client-email');
        var $submitButton = $form.find('button[type="submit"]');
        var formMode = 'create';

        if (! $form.length) {
            return;
        }

        editButtonLabel = translate('clients.table.actions.edit', 'Editar');
        updateSubmitLabel = translate('clients.form.update', 'Actualizar cliente');
        createSubmitLabel = $submitButton.length ? $submitButton.text() : translate('clients.form.submit', 'Crear cliente');

        function setFormMode(mode, client) {
            if (mode === 'edit' && client) {
                formMode = 'edit';

                if ($clientId.length) {
                    $clientId.val(client.id || '');
                }

                if ($nameInput.length) {
                    $nameInput.val(client.name || '');
                }

                if ($emailInput.length) {
                    $emailInput.val(client.email || '');
                }

                if ($submitButton.length) {
                    $submitButton.text(updateSubmitLabel);
                }

                return;
            }

            formMode = 'create';

            if ($clientId.length) {
                $clientId.val('');
            }

            if ($submitButton.length) {
                $submitButton.text(createSubmitLabel);
            }
        }

        $form.on('reset', function () {
            window.setTimeout(function () {
                setFormMode('create');
                resetAlert($alert);
            }, 0);
        });

        if (canEditClients) {
            $tableBody.on('click', '.client-edit-button', function () {
                var $button = $(this);
                var client = {
                    id: $button.attr('data-client-id') || '',
                    name: $button.attr('data-client-name') || '',
                    email: $button.attr('data-client-email') || '',
                };

                setFormMode('edit', client);

                if ($nameInput.length) {
                    $nameInput.trigger('focus');
                }
            });
        }

        $form.on('submit', function (event) {
            event.preventDefault();

            var mode = formMode;
            var requestUrl = mode === 'edit' ? '../api/clients/update.php' : '../api/clients/store.php';

            resetAlert($alert);

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
                    if (response && response.success && response.client) {
                        var message;

                        if (response.message) {
                            message = response.message;
                        } else if (mode === 'edit') {
                            message = translate('clients.update.success', 'El cliente se actualizó correctamente.');
                        } else {
                            message = translate('clients.alert.success', 'El cliente se registró correctamente.');
                        }

                        showAlert($alert, 'success', message);
                        upsertClientRow($tableBody, response.client);

                        if ($form[0]) {
                            $form[0].reset();
                        } else {
                            setFormMode('create');
                        }

                        return;
                    }

                    var validationMessage = translate('clients.alert.validation', 'No fue posible registrar el cliente con la información proporcionada.');

                    if (response && response.message) {
                        validationMessage = response.message;
                    }

                    showAlert($alert, 'warning', validationMessage);
                })
                .fail(function (jqXHR) {
                    var message = translate('clients.alert.error', 'Ocurrió un error al guardar el cliente.');

                    if (jqXHR.responseJSON) {
                        if (jqXHR.responseJSON.message) {
                            message = jqXHR.responseJSON.message;
                        }

                        if (jqXHR.responseJSON.errors) {
                            var details = Object.values(jqXHR.responseJSON.errors).join(' ');
                            if (details) {
                                message += ' ' + details;
                            }
                        }
                    }

                    var status = jqXHR.status;
                    var type = status === 401 || status === 403 ? 'warning' : 'danger';

                    if (status === 419) {
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
    });
})(jQuery);
